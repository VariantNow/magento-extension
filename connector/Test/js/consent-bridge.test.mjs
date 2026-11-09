// Runs view/frontend/web/js/consent-bridge.js against a fake page: node --test apps/magento/connector/Test/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const SOURCE = readFileSync(
  new URL('../../view/frontend/web/js/consent-bridge.js', import.meta.url),
  'utf8'
);

/**
 * A page the bridge runs on. `readyState: 'loading'` is the head, where Magento renders it.
 * `oneTrustScript` is OneTrust's script tag being on the page. Returns the window and helpers
 * to move the page along; `calls` records what the runtime is told.
 */
function page({
  cookie = '',
  dataLayer,
  oneTrustScript = false,
  readyState = 'loading',
  category,
} = {}) {
  const listeners = { window: {}, document: {} };
  const on = (target) => (type, fn) => (listeners[target][type] ||= []).push(fn);
  const intervals = [];
  const calls = [];
  const window = { addEventListener: on('window') };
  if (dataLayer) window.dataLayer = dataLayer;
  const document = {
    cookie,
    readyState,
    oneTrustScript,
    currentScript: {
      getAttribute: (name) => (name === 'data-onetrust-category' ? (category ?? null) : null),
    },
    addEventListener: on('document'),
    querySelector: () => (document.oneTrustScript ? {} : null),
  };
  runInNewContext(SOURCE, { window, document, setInterval: (fn) => intervals.push(fn) });
  const fire = (target, type, event = {}) =>
    (listeners[target][type] || []).forEach((fn) => fn(event));
  return {
    window,
    document,
    calls,
    /** variant.js has run: the runtime's hook exists from now on. */
    runtime() {
      window.Variant = { setAnalyticsConsent: (value) => calls.push(value) };
    },
    parsed() {
      document.readyState = 'interactive';
      fire('document', 'DOMContentLoaded');
    },
    loaded() {
      document.readyState = 'complete';
      fire('window', 'load');
    },
    /** A second passes: the bridge re-checks. */
    tick() {
      intervals.forEach((fn) => fn());
    },
    /** OneTrust's script is added to the page, e.g. by a tag manager after load. */
    addOneTrust() {
      document.oneTrustScript = true;
    },
    oneTrust(groups) {
      window.OnetrustActiveGroups = ',' + groups.join(',') + ',';
      fire('window', 'OneTrustGroupsUpdated', { detail: groups });
    },
    /** gtag has resolved analytics_storage for this visitor's region. */
    gtag(state) {
      window.google_tag_data = { ics: { entries: { analytics_storage: state } } };
    },
  };
}

const optanon = (groups, name = 'OptanonConsent', extra = '') =>
  'OptanonAlertBoxClosed=2026-11-08; ' +
  name +
  '=isGpcEnabled=0&datestamp=Sun+Nov+08+2026&groups=' +
  encodeURIComponent(groups) +
  extra +
  '&landingPath=NotLandingPage';

const consent = (p) => p.window.__VARIANT_ANALYTICS_CONSENT;

/** Daniel Defense's shape: denied for listed regions, granted elsewhere. */
const REGIONAL_DEFAULTS = () => [
  ['consent', 'default', { analytics_storage: 'denied', region: ['IT', 'GB'] }],
  ['consent', 'default', { analytics_storage: 'granted' }],
];

// ---- OneTrust

test("with OneTrust on the page, a returning visitor's saved choice is read from its cookie", () => {
  assert.equal(consent(page({ cookie: optanon('C0001:1,C0002:1'), oneTrustScript: true })), true);
  assert.equal(consent(page({ cookie: optanon('C0001:1,C0002:0'), oneTrustScript: true })), false);
});

test('a OneTrust cookie left behind after the store removed OneTrust does not count', () => {
  // Migrated to a banner that reports through Consent Mode: the old grant must not win.
  const p = page({
    cookie: optanon('C0001:1,C0002:1'),
    dataLayer: [['consent', 'update', { analytics_storage: 'denied' }]],
  });
  assert.equal(consent(p), false);
  assert.equal(consent(page({ cookie: optanon('C0001:1,C0002:1') })), 'pending');
});

test('OneTrust under its suffixed cookie names is read too', () => {
  const name = 'OptanonConsent-3f9a2c';
  assert.equal(consent(page({ cookie: optanon('C0001:1,C0002:1', name), oneTrustScript: true })), true);
  const awaiting = optanon('C0001:1,C0002:1', name, '&AwaitingReconsent=true');
  assert.equal(consent(page({ cookie: awaiting, oneTrustScript: true })), false);
});

test('a new OneTrust visitor is pending until OneTrust answers, then the runtime is told', () => {
  const p = page({ oneTrustScript: true });
  assert.equal(consent(p), 'pending');
  p.runtime();
  p.parsed();
  p.loaded();
  p.tick();
  assert.deepEqual(p.calls, []);

  p.oneTrust(['C0001']); // an EU visitor before choosing
  p.oneTrust(['C0001', 'C0002', 'C0003', 'C0004']); // Allow all
  assert.deepEqual(p.calls, [false, true]);
  assert.equal(consent(p), true);
});

test('an answer before variant.js runs is preset, then told to the runtime once it exists', () => {
  const p = page({ oneTrustScript: true });
  p.oneTrust(['C0001', 'C0002']);
  assert.equal(consent(p), true);
  assert.deepEqual(p.calls, []);
  p.runtime();
  p.tick();
  assert.deepEqual(p.calls, [true]);
});

test('a decline known before variant.js is also told to the runtime explicitly', () => {
  // A returning Strict-mode visitor who accepted earlier, then declined in OneTrust: the
  // runtime must hear setAnalyticsConsent(false), which drops its Strict consent marker.
  const p = page({ cookie: optanon('C0001:1,C0002:0'), oneTrustScript: true });
  assert.equal(consent(p), false);
  p.runtime();
  p.tick();
  p.tick();
  assert.deepEqual(p.calls, [false]);
});

test('withdrawing Performance cookies is reported', () => {
  const p = page({ cookie: optanon('C0001:1,C0002:1'), oneTrustScript: true });
  p.runtime();
  p.oneTrust(['C0001']);
  assert.deepEqual(p.calls, [false]);
});

test('duplicate OneTrust cookies count only when they agree', () => {
  const second = (groups) => '; OptanonConsent=isGpcEnabled=0&groups=' + encodeURIComponent(groups);
  const answer = (cookie) => consent(page({ cookie, oneTrustScript: true }));
  // A stale grant next to the active decline: not an answer.
  assert.equal(answer(optanon('C0001:1,C0002:1') + second('C0001:1,C0002:0')), 'pending');
  assert.equal(answer(optanon('C0001:1,C0002:0') + second('C0001:1,C0002:0')), false);
  assert.equal(answer(optanon('C0001:1,C0002:1') + second('C0001:1,C0002:1')), true);
});

test("OneTrust's old choice does not count while it asks the visitor to consent again", () => {
  const awaiting = optanon('C0001:1,C0002:1', 'OptanonConsent', '&AwaitingReconsent=true');
  const p = page({ cookie: awaiting, oneTrustScript: true });
  assert.equal(consent(p), false);
  p.runtime();
  // OneTrust reapplies the old groups on load; that is not the new answer.
  p.oneTrust(['C0001', 'C0002']);
  p.tick();
  assert.deepEqual(p.calls, [false]);

  // The visitor answers the new banner: OneTrust rewrites the cookie without the flag.
  p.document.cookie = optanon('C0001:1,C0002:1');
  p.oneTrust(['C0001', 'C0002']);
  assert.deepEqual(p.calls, [false, true]);
});

test("OneTrust's placeholder groups are not an answer; the saved cookie is", () => {
  // OneTrust sets ',' or ',,' before it applies the visitor's saved groups.
  for (const placeholder of [',', ',,']) {
    const p = page({ cookie: optanon('C0001:1,C0002:1'), oneTrustScript: true });
    p.runtime();
    p.window.OnetrustActiveGroups = placeholder;
    p.tick();
    assert.equal(consent(p), true, placeholder);
    assert.deepEqual(p.calls, [true], placeholder);
  }
});

test("a store's own OneTrust category ID replaces C0002", () => {
  const cookie = optanon('C0001:1,C0002:1,SPD_BG:0');
  assert.equal(consent(page({ cookie, category: 'SPD_BG', oneTrustScript: true })), false);
  assert.equal(consent(page({ cookie, oneTrustScript: true })), true);
  // Anything that is not an ID falls back to C0002.
  assert.equal(consent(page({ cookie, category: 'C0002)|(', oneTrustScript: true })), true);

  const p = page({ category: 'SPD_BG', oneTrustScript: true });
  p.runtime();
  p.oneTrust(['C0001', 'SPD_BG']);
  assert.deepEqual(p.calls, [true]);
});

test("OneTrust's own allow is not revoked while its state is re-read", () => {
  const p = page({ oneTrustScript: true });
  p.runtime();
  // The event carried the answer; the active-groups global is not set in this page.
  p.oneTrust(['C0001', 'C0002']);
  delete p.window.OnetrustActiveGroups;
  p.loaded();
  p.tick();
  assert.deepEqual(p.calls, [true]);
});

test('OneTrust added after a Consent Mode allow holds the page until it answers', () => {
  // Allowed by Consent Mode before variant.js ran: the runtime read it from the preset.
  const p = page({ dataLayer: [['consent', 'update', { analytics_storage: 'granted' }]] });
  assert.equal(consent(p), true);
  p.runtime();

  p.addOneTrust();
  p.tick();
  assert.deepEqual(p.calls, [false]);

  p.oneTrust(['C0001', 'C0002']);
  p.tick();
  assert.deepEqual(p.calls, [false, true]);
});

test('OneTrust arriving with an unreadable cookie after a Consent Mode allow withdraws it', () => {
  const p = page({ dataLayer: [['consent', 'update', { analytics_storage: 'granted' }]] });
  assert.equal(consent(p), true);
  p.runtime();

  p.addOneTrust();
  p.document.cookie = 'OptanonConsent=isGpcEnabled=0&groups=C0002%3A1%2%E0%A4%A';
  p.tick();
  assert.deepEqual(p.calls, [false]);
});

test('OneTrust wins over Consent Mode when both are on the page', () => {
  const p = page({
    cookie: optanon('C0001:1,C0002:0'),
    oneTrustScript: true,
    dataLayer: [['consent', 'update', { analytics_storage: 'granted' }]],
  });
  p.runtime();
  p.gtag({ default: true, update: true });
  p.parsed();
  p.loaded();
  p.tick();
  assert.equal(consent(p), false);
  assert.deepEqual(p.calls, [false]);
});

// ---- Google Consent Mode

test('a denied default holds the visitor pending, never reports a decline', () => {
  // A decline would clear Strict mode's record of an earlier acceptance before the banner
  // restores it; a default is only "not answered yet".
  const dataLayer = [];
  const p = page({ dataLayer });
  p.runtime();
  dataLayer.push(['consent', 'default', { analytics_storage: 'denied' }]);
  p.parsed();
  p.loaded();
  p.tick();
  assert.equal(consent(p), 'pending');
  assert.deepEqual(p.calls, []);

  // No click needed: an iframe banner or the keyboard is seen on the next re-check.
  dataLayer.push(['consent', 'update', { analytics_storage: 'granted', ad_storage: 'denied' }]);
  p.tick();
  dataLayer.push(['consent', 'update', { analytics_storage: 'denied' }]);
  p.tick();
  assert.deepEqual(p.calls, [true, false]);
});

test('a global granted default is an answer even when gtag never loads', () => {
  const granted = ['consent', 'default', { analytics_storage: 'granted' }];
  assert.equal(consent(page({ dataLayer: [granted] })), true);

  // Region-specific defaults make it ambiguous without gtag: pending.
  const regionalGranted = ['consent', 'default', { analytics_storage: 'granted', region: ['US'] }];
  assert.equal(consent(page({ dataLayer: [regionalGranted, granted] })), 'pending');
  assert.equal(consent(page({ dataLayer: REGIONAL_DEFAULTS() })), 'pending');

  // A denial added later by a tag manager withdraws the allow.
  const p = page({ dataLayer: [granted] });
  p.runtime();
  p.window.dataLayer.push(['consent', 'default', { analytics_storage: 'denied' }]);
  p.tick();
  assert.deepEqual(p.calls, [false]);
});

test('regional defaults: gtag decides which applies, and until it does the page is held', () => {
  // An EU visitor: gtag applied the denied regional default.
  const eu = page({ dataLayer: REGIONAL_DEFAULTS() });
  assert.equal(consent(eu), 'pending');
  eu.runtime();
  eu.loaded();
  eu.gtag({ default: false, region: 'IT' });
  eu.tick();
  assert.deepEqual(eu.calls, []);
  eu.gtag({ default: false, region: 'IT', update: true }); // accepted in the banner
  eu.tick();
  assert.deepEqual(eu.calls, [true]);

  // A US visitor: the global granted default applies.
  const us = page({ dataLayer: REGIONAL_DEFAULTS() });
  us.runtime();
  us.gtag({ default: true, region: 'US' });
  us.parsed();
  assert.deepEqual(us.calls, [true]);
});

test("gtag's current state wins over an older update still in the dataLayer", () => {
  // Granted through gtag first, then withdrawn through GTM's consent API, which updates
  // gtag's state without a new dataLayer entry.
  const p = page({ dataLayer: [['consent', 'update', { analytics_storage: 'granted' }]] });
  p.runtime();
  p.gtag({ default: false, update: true });
  p.loaded();
  p.gtag({ default: false, update: false });
  p.tick();
  assert.deepEqual(p.calls, [true, false]);
  assert.equal(consent(p), false);
});

test("only Google's exact consent values count", () => {
  for (const value of ['Denied', 'pending', '']) {
    const dataLayer = [['consent', 'default', { analytics_storage: value }]];
    // An unknown default is not a grant: the visitor is held.
    assert.equal(consent(page({ dataLayer })), 'pending', value);
  }
  // An unknown update is not an answer; the default still decides.
  const p = page({
    dataLayer: [
      ['consent', 'default', { analytics_storage: 'granted' }],
      ['consent', 'update', { analytics_storage: 'Granted' }],
    ],
  });
  assert.equal(consent(p), true);
});

// ---- no consent tool

test('with no consent tool the page stays pending, even after load', () => {
  // Gate tracking and Strict are for stores with a consent tool. Allowing because the page
  // loaded would flush queued analytics before a tool a tag manager adds late could answer.
  const p = page({ dataLayer: [['js', new Date()], ['config', 'G-1']] });
  p.runtime();
  p.parsed();
  p.loaded();
  p.tick();
  assert.equal(consent(p), 'pending');
  assert.deepEqual(p.calls, []);

  // A consent tool loaded late, through a tag manager, then answering.
  p.window.dataLayer.push(['consent', 'default', { analytics_storage: 'denied' }]);
  p.tick();
  p.window.dataLayer.push(['consent', 'update', { analytics_storage: 'granted' }]);
  p.tick();
  assert.deepEqual(p.calls, [true]);
});

test('a malformed consent cookie leaves the page pending, never throws', () => {
  const p = page({ cookie: 'OptanonConsent=groups=%E0%A4%A', oneTrustScript: true });
  assert.equal(consent(p), 'pending');
  p.loaded();
  p.tick();
});

test('a page already loaded with no consent tool is still pending', () => {
  assert.equal(consent(page({ readyState: 'complete' })), 'pending');
});
