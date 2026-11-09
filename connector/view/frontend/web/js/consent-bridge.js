/**
 * Copyright © Variant. All rights reserved.
 *
 * Tells Variant's storefront runtime the visitor's analytics consent on a Magento store, which
 * has no consent API of its own (Shopify stores have the Customer Privacy API). Rendered inline
 * by AppendRuntimeTags ahead of Variant's tags; this comment block is stripped there.
 *
 * Only the shop's "Cookie consent" setting decides what the answer gates: with Off, the default,
 * it changes nothing. Gate tracking and Strict are for stores with a consent tool that loads for
 * every visitor (answering "allowed" itself where consent is not required, as OneTrust's
 * geolocation rules and Consent Mode's regional defaults do), and on Magento that tool must be one
 * this script reads: until it answers, the visitor is not allowed. There is no "no tool found, so
 * allowed" fallback on purpose: the page cannot tell a tool that will never load from one a tag
 * manager adds late, and the late one would answer after queued analytics had been sent.
 *
 * The answer reaches the runtime through its documented hooks: before variant.js runs,
 * window.__VARIANT_ANALYTICS_CONSENT (true, false, or 'pending' = a consent tool will answer);
 * after, Variant.setAnalyticsConsent(). Only a real answer is reported as a decline: a default
 * or a tool that has not answered yet is a hold, which leaves the visitor pending (and withdraws
 * only an earlier "allowed"), so it never clears Strict mode's record of an earlier acceptance.
 *
 * Where the answer comes from, in order:
 *  1. OneTrust, while it is on the page (its script or globals; a cookie left behind after a store
 *     removed OneTrust does not count). The store's analytics category: "Performance cookies"
 *     (C0002) by default; a store whose OneTrust uses another ID sets it under Stores >
 *     Configuration > Variant > Advanced, which reaches this script as the tag's
 *     data-onetrust-category. Read from OneTrust's active groups, its OneTrustGroupsUpdated event,
 *     or its OptanonConsent cookie (also under the suffixed names OneTrust uses when a site manages
 *     consent apart from the root domain; several copies count only when they agree). While
 *     OneTrust asks the visitor to consent again (AwaitingReconsent), the old choice does not
 *     count.
 *  2. Otherwise Google Consent Mode, for analytics_storage. An answer: the update gtag recorded
 *     (google_tag_data.ics), else the latest exact `update` in the dataLayer. A default: the one
 *     gtag resolved for this visitor's region, else, before gtag loads, a default granting
 *     everyone (no region, nothing denied) allows and any other denies. Only Google's exact values
 *     count.
 *
 * Re-checked every second as well as on the events above: banners in iframes or answered from
 * the keyboard change Consent Mode without a click on the page, and GTM replaces dataLayer.push,
 * so wrapping it would not see every update.
 *
 * It also tells the store's own server, through the `variant_attribution` cookie, whether this
 * visitor's purchases may be matched to the tests they saw: the cart and order keep Variant's
 * visitor ids only while it is set (Variant\Connector\Observer\SyncVisitorIds). The runtime's
 * answer decides (window.__VARIANT_CONSENT, shared by its bundles), so the Cookie consent setting
 * applies as it does to analytics: with Off it is always set; with Gate tracking only once the
 * visitor allows analytics. Before variant.js has run it is never set.
 */
(function (w, d) {
  var configured = d.currentScript && d.currentScript.getAttribute('data-onetrust-category');
  var CATEGORY = /^[A-Za-z0-9_-]+$/.test(configured || '') ? configured : 'C0002';
  // OptanonConsent, or a suffixed copy of it.
  var CONSENT_COOKIES = /(?:^|;\s*)OptanonConsent[^=;\s]*=[^;]*/g;
  var ATTRIBUTION = 'variant_attribution';
  var ATTRIBUTION_SET = /(?:^|;\s*)variant_attribution=1(?:;|$)/;
  var answer;
  var told;
  var oneTrustAnswered = false;

  /**
   * Hand the runtime the current answer once it exists, and every change after. An answer
   * preset before variant.js ran is told explicitly as well, so a decline also reaches the
   * runtime's withdrawal handling (Strict mode's consent marker) and not only the preset.
   */
  function tell() {
    var api = w.Variant;
    if (
      answer !== undefined &&
      answer !== told &&
      api &&
      typeof api.setAnalyticsConsent === 'function'
    ) {
      told = answer;
      api.setAnalyticsConsent(answer);
    }
  }

  function report(value) {
    if (value !== answer) {
      answer = value;
      w.__VARIANT_ANALYTICS_CONSENT = value;
    }
    tell();
  }

  /** No answer yet: stay pending, withdrawing only an earlier "allowed". */
  function hold() {
    if (answer === true) {
      report(false);
    } else {
      tell();
    }
  }

  function hasCategory(groups) {
    return (',' + groups + ',').indexOf(',' + CATEGORY + ',') !== -1;
  }

  function consentCookies() {
    var found = d.cookie.match(CONSENT_COOKIES) || [];
    for (var i = 0; i < found.length; i++) {
      found[i] = found[i].slice(found[i].indexOf('=') + 1);
    }
    return found;
  }

  function awaitingReconsent() {
    var cookies = consentCookies();
    for (var i = 0; i < cookies.length; i++) {
      if (/(?:^|&)AwaitingReconsent=true(?:&|$)/.test(cookies[i])) {
        return true;
      }
    }
    return false;
  }

  function oneTrustOnPage() {
    return !!(
      w.OneTrust ||
      w.OptanonWrapper ||
      d.querySelector('script[src*="cookielaw.org"],script[src*="onetrust"]')
    );
  }

  /** OneTrust's answer for the category: true, false, or undefined while it has none. */
  function oneTrust() {
    if (awaitingReconsent()) {
      return false;
    }
    var active = w.OnetrustActiveGroups;
    // OneTrust sets ',' or ',,' before it has applied the visitor's groups: not an answer yet.
    if (typeof active === 'string' && /[^,\s]/.test(active)) {
      return hasCategory(active);
    }
    // A site can carry several OptanonConsent cookies (different paths or domains, a known
    // OneTrust failure). Only an answer they all agree on counts.
    var cookies = consentCookies();
    var value;
    for (var i = 0; i < cookies.length; i++) {
      var one = cookieAnswer(cookies[i]);
      if (one === undefined || (value !== undefined && one !== value)) {
        return undefined;
      }
      value = one;
    }
    return value;
  }

  /** One OptanonConsent value's answer for the category, or undefined. */
  function cookieAnswer(raw) {
    var parts = raw.split('&');
    for (var i = 0; i < parts.length; i++) {
      if (parts[i].indexOf('groups=') === 0) {
        var groups;
        try {
          groups = decodeURIComponent(parts[i].slice(7));
        } catch (e) {
          // Unreadable is unanswered.
          return undefined;
        }
        var match = groups.match(new RegExp('(?:^|,)' + CATEGORY + ':([01])'));
        return match ? match[1] === '1' : undefined;
      }
    }
    return undefined;
  }

  /**
   * Consent Mode for analytics_storage: `answer`, the visitor's own choice, and `standing`, the
   * default that applies until they choose (true allows, false holds).
   */
  function consentMode() {
    var layer = w.dataLayer;
    var update;
    var denied = false;
    var regional = false;
    var granted = false;
    for (var i = 0; layer && i < layer.length; i++) {
      var entry = layer[i];
      var settings = entry && entry[0] === 'consent' && entry[2];
      if (!settings || typeof settings.analytics_storage !== 'string') {
        continue;
      }
      var value = settings.analytics_storage;
      if (entry[1] === 'update') {
        if (value === 'granted' || value === 'denied') {
          update = value === 'granted';
        }
      } else if (entry[1] === 'default') {
        if (value !== 'granted') {
          denied = true;
        } else if (settings.region) {
          regional = true;
        } else {
          granted = true;
        }
      }
    }
    var ics = w.google_tag_data && w.google_tag_data.ics;
    var state = ics && ics.entries && ics.entries.analytics_storage;
    var result = { answer: update };
    if (state && typeof state.update === 'boolean') {
      result.answer = state.update;
    }
    if (state && typeof state['default'] === 'boolean') {
      result.standing = state['default'];
    } else if (denied) {
      result.standing = false;
    } else if (granted && !regional) {
      result.standing = true;
    }
    return result;
  }

  /** Keep the attribution cookie in step with whether the runtime allows analytics now. */
  function share() {
    try {
      var state = w.__VARIANT_CONSENT;
      var allowed = !!(state && typeof state.allowed === 'function' && state.allowed());
      if (allowed !== ATTRIBUTION_SET.test(d.cookie)) {
        var secure = w.location && w.location.protocol === 'https:' ? '; Secure' : '';
        d.cookie =
          ATTRIBUTION + (allowed ? '=1' : '=; max-age=0') + '; path=/; SameSite=Lax' + secure;
      }
    } catch (e) {
      // An unreadable answer or blocked cookies: purchases are simply not attributed.
    }
  }

  function check() {
    read();
    share();
  }

  function read() {
    try {
      if (oneTrustOnPage()) {
        var value = oneTrust();
        if (value !== undefined) {
          oneTrustAnswered = true;
          report(value);
        } else if (!oneTrustAnswered) {
          // OneTrust is here but has not answered: an "allowed" it did not give is withdrawn.
          hold();
        } else {
          tell();
        }
        return;
      }
      var mode = consentMode();
      if (mode.answer !== undefined) {
        report(mode.answer);
      } else if (mode.standing === true) {
        report(true);
      } else if (mode.standing === false) {
        hold();
      } else {
        tell();
      }
    } catch (e) {
      // A consent tool's malformed state must never break the page.
    }
  }

  check();
  if (answer === undefined) {
    w.__VARIANT_ANALYTICS_CONSENT = 'pending';
  }
  w.addEventListener('OneTrustGroupsUpdated', function (event) {
    if (
      !awaitingReconsent() &&
      event &&
      Object.prototype.toString.call(event.detail) === '[object Array]'
    ) {
      oneTrustAnswered = true;
      report(event.detail.indexOf(CATEGORY) !== -1);
      share();
    } else {
      check();
    }
  });
  d.addEventListener('DOMContentLoaded', check);
  w.addEventListener('load', check);
  setInterval(check, 1000);
})(window, document);
