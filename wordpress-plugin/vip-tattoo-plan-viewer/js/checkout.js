/**
 * "Открыть доступ к обучению" popup — collects email + phone and submits
 * to the SAME create-checkout REST route the main vip-tattoo-landing
 * plugin's own popup uses (window.VIP_TATTOO_CHECKOUT_REST_BASE, printed
 * by vip_tattoo_plan_render_globals() from that plugin's rest_url()) --
 * both plugins live on the same WordPress install, so this is just a
 * same-origin fetch to an endpoint some other active plugin registered,
 * not a duplicate payment implementation. On success the browser is
 * redirected straight to the returned checkout_url (PayPal/Stripe hosted
 * page), exactly like the landing page's own popup does.
 */
(function () {
  var restBase = window.VIP_TATTOO_CHECKOUT_REST_BASE;
  var overlay = document.getElementById('vtpPopupOverlay');
  var card = document.getElementById('vtpPopupCard');
  var openBtn = document.getElementById('vtpOpenCheckout');
  var closeBtn = document.getElementById('vtpPopupClose');
  var form = document.getElementById('vtpCheckoutForm');

  function openPopup() {
    if (!overlay || !card) return;
    overlay.classList.add('is-open');
    card.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    if (window.__vtpTrack) window.__vtpTrack('checkout_open');
    if (typeof window.fbq === 'function') window.fbq('track', 'Lead');
    if (typeof window.gtag === 'function') window.gtag('event', 'generate_lead');
  }

  function closePopup() {
    if (!overlay || !card) return;
    overlay.classList.remove('is-open');
    card.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  if (openBtn) openBtn.addEventListener('click', openPopup);
  if (closeBtn) closeBtn.addEventListener('click', closePopup);
  if (overlay) overlay.addEventListener('click', closePopup);

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!restBase) {
        alert('Оплата временно недоступна. Напиши нам в Telegram.');
        return;
      }

      var button = form.querySelector('button[type="submit"]');
      var originalText = button ? button.innerHTML : '';
      if (button) {
        button.disabled = true;
        button.textContent = 'Переход к оплате…';
      }

      var email = form.querySelector('input[name="email"]').value;
      var phone = form.querySelector('input[name="phone"]').value;
      var params = new URLSearchParams(window.location.search);

      fetch(restBase + 'create-checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          email: email,
          phone: phone,
          name: '',
          utm_source: params.get('utm_source') || '',
          utm_medium: params.get('utm_medium') || '',
          utm_campaign: params.get('utm_campaign') || '',
          lang: document.documentElement.lang || '',
          popup_source: 'plan-viewer',
        }),
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.checkout_url) {
            window.location.href = data.checkout_url;
            return;
          }
          throw new Error(data.error || 'Unknown error');
        })
        .catch(function (err) {
          console.error('[VIP Tattoo Plan] Checkout error:', err);
          alert('Не удалось перейти к оплате. Попробуйте ещё раз или напишите нам в Telegram.');
          if (button) {
            button.disabled = false;
            button.innerHTML = originalText;
          }
        });
    });
  }
})();
