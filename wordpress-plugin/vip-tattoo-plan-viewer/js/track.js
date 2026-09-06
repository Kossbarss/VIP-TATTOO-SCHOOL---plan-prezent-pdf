/**
 * Fires one "view" event on load and one event per click on any
 * [data-vtp-event="..."] element (checkout_click / contact_click, tagged
 * directly on the "Открыть доступ к обучению" Stripe Payment Link and the
 * "Написать Виктории" Telegram link in content/body-plan.html), each
 * carrying a per-page-load token so the server-side REST route can
 * de-duplicate retried calls. Also mirrors events into whichever
 * ad-platform pixels are configured (GA4/dataLayer, Meta Pixel, TikTok
 * Pixel), so conversion tracking on those platforms doesn't depend on
 * parsing server logs separately. The Stripe link itself just navigates
 * normally (no popup, no order-creation REST call) -- Stripe hosts the
 * whole checkout, so there's nothing else for this plugin to do.
 */
(function () {
  function readParam(name) {
    var m = new URLSearchParams(window.location.search);
    return m.get(name) || '';
  }

  function visitToken() {
    var key = 'vtp_visit_token';
    try {
      var existing = sessionStorage.getItem(key);
      if (existing) return existing;
      var token = 'v_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
      sessionStorage.setItem(key, token);
      return token;
    } catch (e) {
      // sessionStorage unavailable (private mode, etc.) -- fall back to a
      // token that's stable for the lifetime of this page load only.
      return 'v_' + Date.now().toString(36);
    }
  }

  var TOKEN = visitToken();

  function track(eventType) {
    if (!window.VIP_TATTOO_PLAN_REST_URL) return;

    var payload = {
      visit_token: TOKEN,
      event_type: eventType,
      utm_source: readParam('utm_source'),
      utm_medium: readParam('utm_medium'),
      utm_campaign: readParam('utm_campaign'),
      referrer: document.referrer || ''
    };

    fetch(window.VIP_TATTOO_PLAN_REST_URL + 'track', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.VIP_TATTOO_PLAN_NONCE || ''
      },
      body: JSON.stringify(payload),
      keepalive: true
    }).catch(function () {
      // Best-effort only -- a failed tracking call must never block or
      // error out the actual page/download for the visitor.
    });

    var ga4EventNames = {
      checkout_click: 'plan_checkout_click',
      contact_click: 'plan_contact_click',
      view: 'plan_view',
    };
    if (window.VIP_TATTOO_PLAN_HAS_GA4 && typeof window.gtag === 'function') {
      window.gtag('event', ga4EventNames[eventType] || eventType);
    }
    if (window.VIP_TATTOO_PLAN_HAS_META_PIXEL && typeof window.fbq === 'function' && eventType === 'checkout_click') {
      window.fbq('track', 'Lead');
    }
    if (window.VIP_TATTOO_PLAN_HAS_TIKTOK && window.ttq && eventType === 'checkout_click') {
      window.ttq.track('InitiateCheckout');
    }
  }

  window.__vtpTrack = track;

  track('view');

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-vtp-event]');
    if (el) track(el.getAttribute('data-vtp-event'));
  });
})();
