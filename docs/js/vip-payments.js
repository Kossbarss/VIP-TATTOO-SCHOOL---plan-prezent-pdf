;(function () {
  'use strict'

  // window.VIP_TATTOO_REST_URL is set by an inline <script> the plugin
  // template prints, same pattern as VIP_TATTOO_ASSET_BASE in
  // vip-wp-loader.js. VIP_TATTOO_LOCALE is already set by the same
  // script (used sitewide for RU/UA copy). After approval on PayPal's
  // hosted checkout, the buyer briefly bounces back through our own
  // paypal-return endpoint (which captures the payment) and then on to
  // the Telegram bot (see vip_tattoo_rest_paypal_return() in
  // includes/payments.php) -- so this file never needs to show a
  // post-payment banner itself, the browser leaves this page on
  // payment success either way.
  var restUrl = window.VIP_TATTOO_REST_URL
  var isUk = window.VIP_TATTOO_LOCALE === 'uk'

  // Captured once per browser session (sessionStorage survives internal
  // navigation but not a fresh visit) so the *original* ad/campaign that
  // brought the visitor in is what reaches the CRM/Sheets row, even if
  // they submit the form a few page views later with no UTM params left
  // in the URL bar.
  var utm = (function () {
    var stored = {}
    try { stored = JSON.parse(sessionStorage.getItem('vip_tattoo_utm') || '{}') } catch (e) {}
    var params = new URLSearchParams(window.location.search)
    var fresh = {
      utm_source: params.get('utm_source') || '',
      utm_medium: params.get('utm_medium') || '',
      utm_campaign: params.get('utm_campaign') || '',
    }
    var hasFresh = fresh.utm_source || fresh.utm_medium || fresh.utm_campaign
    var result = hasFresh ? fresh : stored
    try { sessionStorage.setItem('vip_tattoo_utm', JSON.stringify(result)) } catch (e) {}
    return result
  })()

  // Written by the separate analytics plugin's tracker.js (if that
  // plugin is installed/active) as the visitor scrolls -- read here,
  // at the moment of submission, as "how far did they get before
  // deciding to buy". Empty string if that plugin isn't running.
  function lastBlockSeen() {
    try { return sessionStorage.getItem('vip_tattoo_last_block') || '' } catch (e) { return '' }
  }

  // Written by site-interactions.js the moment any [data-popup-open]
  // trigger (or the sticky bar) is clicked -- which CTA label actually
  // got the visitor into the popup.
  function popupSource() {
    try { return sessionStorage.getItem('vip_tattoo_popup_source') || '' } catch (e) { return '' }
  }

  var copy = isUk ? {
    loading: 'Перехід до оплати…',
    error: 'Не вдалося перейти до оплати. Спробуйте ще раз або напишіть нам у Telegram.',
  } : {
    loading: 'Переход к оплате…',
    error: 'Не удалось перейти к оплате. Попробуйте ещё раз или напишите нам в Telegram.',
  }

  function submitToCheckout(form, fields) {
    var button = form.querySelector('button[type="submit"]')
    var originalText = button ? button.innerHTML : ''
    if (button) {
      button.disabled = true
      button.innerHTML = '<span class="btn-stardust-wrap">' + copy.loading + '</span>'
    }

    // Fired the moment someone submits contact info (before payment even
    // starts) -- each platform's own "lead" event, distinct from the
    // "Purchase"/conversion event fired server-side once an order is
    // actually paid. Every call is independently guarded: each library
    // only exists on window once its own ID is configured in the
    // Analytics plugin's settings (see includes/analytics.php) *and*
    // Cookiebot has let the visitor's consent through -- a missing/
    // unconsented one is silently skipped, never blocks the others.
    if (typeof fbq === 'function') {
      fbq('track', 'Lead')
    }
    if (typeof ttq !== 'undefined' && ttq.track) {
      ttq.track('SubmitForm')
    }
    if (typeof gtag === 'function') {
      gtag('event', 'generate_lead')
      // Google Ads conversions use a different event shape than GA4's
      // named events -- needs "AW-ID/LABEL" from the Analytics plugin's
      // settings (see vip_tattoo_analytics_build_head_injection_html());
      // without a label configured there this global is never set, and
      // the GA4 generate_lead event above still fires on its own.
      if (window.VIP_TATTOO_GADS_LEAD_SEND_TO) {
        gtag('event', 'conversion', { send_to: window.VIP_TATTOO_GADS_LEAD_SEND_TO })
      }
    }

    fetch(restUrl + 'create-checkout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(fields),
    })
      .then(function (res) { return res.json() })
      .then(function (data) {
        if (data.checkout_url) {
          // Fire-and-forget notice for anything listening (currently the
          // separate analytics plugin, if installed) that checkout began
          // -- token is echoed back in the Telegram deep-link URL
          // (?start=<token>), no extra request needed to learn it.
          try {
            var match = /[?&]start=([^&]+)/.exec(data.checkout_url)
            window.dispatchEvent(new CustomEvent('vip-tattoo:checkout-started', {
              detail: { token: match ? decodeURIComponent(match[1]) : null },
            }))
          } catch (e) {}
          window.location.href = data.checkout_url
          return
        }
        throw new Error(data.error || 'Unknown error')
      })
      .catch(function (err) {
        console.error('[VIP Tattoo] Checkout error:', err)
        alert(copy.error)
        if (button) {
          button.disabled = false
          button.innerHTML = originalText
        }
      })
  }

  function wireForm(form, extract) {
    if (!form) return
    form.addEventListener('submit', function (e) {
      e.preventDefault()
      submitToCheckout(form, extract(form))
    })
  }

  wireForm(document.querySelector('.order-form'), function (form) {
    return {
      name: form.querySelector('input[type="text"]') ? form.querySelector('input[type="text"]').value : '',
      email: form.querySelector('input[type="email"]') ? form.querySelector('input[type="email"]').value : '',
      phone: '',
      utm_source: utm.utm_source,
      utm_medium: utm.utm_medium,
      utm_campaign: utm.utm_campaign,
      lang: window.VIP_TATTOO_LOCALE || '',
      last_block: lastBlockSeen(),
      popup_source: popupSource(),
    }
  })

  wireForm(document.querySelector('.popup-form'), function (form) {
    return {
      name: '',
      email: form.querySelector('input[type="email"]') ? form.querySelector('input[type="email"]').value : '',
      phone: form.querySelector('input[type="tel"]') ? form.querySelector('input[type="tel"]').value : '',
      utm_source: utm.utm_source,
      utm_medium: utm.utm_medium,
      utm_campaign: utm.utm_campaign,
      lang: window.VIP_TATTOO_LOCALE || '',
      last_block: lastBlockSeen(),
      popup_source: popupSource(),
    }
  })
})()
