/**
 * Fires one "view" event on load and one event per click on any
 * [data-vtp-event="..."] element (download / contact_click), each
 * carrying a per-page-load token so the server-side REST route can
 * de-duplicate retried calls. Also mirrors events into whichever
 * ad-platform pixels are configured (GA4/dataLayer, Meta Pixel, TikTok
 * Pixel), so conversion tracking on those platforms doesn't depend on
 * parsing server logs separately. Exposes window.__vtpTrack so
 * checkout.js can log the "checkout_open" event through the same path.
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
      download: 'plan_pdf_download',
      checkout_open: 'plan_checkout_open',
      contact_click: 'plan_contact_click',
      view: 'plan_pdf_view',
    };
    if (window.VIP_TATTOO_PLAN_HAS_GA4 && typeof window.gtag === 'function') {
      window.gtag('event', ga4EventNames[eventType] || eventType);
    }
    if (window.VIP_TATTOO_PLAN_HAS_META_PIXEL && typeof window.fbq === 'function' && eventType === 'download') {
      window.fbq('track', 'Lead');
    }
    if (window.VIP_TATTOO_PLAN_HAS_TIKTOK && window.ttq && eventType === 'download') {
      window.ttq.track('Download');
    }
  }

  window.__vtpTrack = track;

  track('view');

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-vtp-event]');
    if (el) track(el.getAttribute('data-vtp-event'));
  });
})();
