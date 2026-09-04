;(function () {
  'use strict'

  // GitHub Pages serves this landing as static files with no WordPress
  // backend behind it, so vip-payments.js (loaded right after this file,
  // unmodified from the real plugin) has nothing to POST create-checkout
  // to. This shim intercepts just that one request and hands back the
  // same shape vip-payments.js expects, sending the visitor straight to
  // the Telegram bot instead of a Stripe/PayPal checkout -- once this
  // page runs on the real WordPress site, VIP_TATTOO_REST_URL points at
  // a real endpoint and this file is not loaded at all.
  var TELEGRAM_BOT_URL = 'https://t.me/mentor_tatoo_Viktoria_bot'

  var originalFetch = window.fetch
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || ''
    if (url.indexOf('create-checkout') !== -1) {
      return Promise.resolve({
        json: function () {
          return Promise.resolve({ checkout_url: TELEGRAM_BOT_URL })
        },
      })
    }
    return originalFetch.apply(window, arguments)
  }
})()
