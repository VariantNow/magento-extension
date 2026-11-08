/**
 * Copyright © Variant. All rights reserved.
 *
 * window.VariantMagento — the add-to-cart Variant's variant code uses on a Magento store when a
 * line must carry a recommendation receipt, which Magento's own add-to-cart cannot send.
 *
 * Turning a discount experiment on is not done here: Variant's store script does it through
 * `Variant.discounts.activate()`, which writes the `variant_discounts` cookie that
 * Variant_Pricing copies onto the cart. Keeping that in the script lets it change without a
 * module release.
 *
 * Plain script, no RequireJS, so it is usable before Magento's widgets load.
 */
(function () {
  'use strict';

  var refreshCart = function () {
    if (typeof window.require === 'function') {
      window.require(['Magento_Customer/js/customer-data'], function (customerData) {
        customerData.reload(['cart'], true);
      });
    }
  };

  var formKey = function () {
    var match = document.cookie.match(/(?:^|; )form_key=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
  };

  var api = (window.VariantMagento = window.VariantMagento || {});

  /**
   * Add a product through Magento's regular add-to-cart, optionally carrying a recommendation
   * receipt (`variant_rec_token`) that a recipe-scoped discount verifies.
   *
   * Resolves false when Magento refused the add. Its AJAX add-to-cart answers 200 either way
   * (out of stock, missing options, a cart rule): an add answers `[]`, a refusal
   * `{"backUrl": <the page it came from>}`, leaving the reason in Magento's messages for the
   * page to show. A `backUrl` to the cart is an add on a store set to redirect to the cart.
   *
   * @param {number|string} productId
   * @param {{qty?: number, recToken?: string, superAttribute?: Object}} [options]
   * @return {Promise<boolean>}
   */
  api.addToCart = function (productId, options) {
    var opts = options || {};
    var body = new URLSearchParams();
    body.set('product', String(productId));
    body.set('qty', String(opts.qty || 1));
    body.set('form_key', formKey());
    if (opts.recToken) {
      body.set('variant_rec_token', opts.recToken);
    }
    Object.keys(opts.superAttribute || {}).forEach(function (attributeId) {
      body.set('super_attribute[' + attributeId + ']', String(opts.superAttribute[attributeId]));
    });
    // BASE_URL carries a store code or subfolder; a root-relative path would miss them.
    var base = window.BASE_URL || '/';
    var ajax = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    return fetch(base + 'checkout/cart/add/', Object.assign({ method: 'POST', body: body }, ajax))
      .then(function (response) {
        if (!response.ok) {
          return false;
        }
        return response
          .json()
          .then(function (result) {
            var backUrl = result && result.backUrl;
            return !backUrl || /\/checkout\/cart\/?(\?|#|$)/.test(String(backUrl));
          })
          .catch(function () {
            // The add answered; only its verdict could not be read.
            return true;
          });
      })
      .then(function (added) {
        refreshCart();
        return added;
      });
  };
})();
