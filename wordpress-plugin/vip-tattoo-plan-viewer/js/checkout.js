/**
 * Only active when window.VIP_TATTOO_PLAN_CHECKOUT_MODE === 'api' (set by
 * "Режим оплати" on the VIP Tattoo План: Оплата settings page). In the
 * default 'link' mode the CTA stays a plain <a href> to the configured
 * Stripe Payment Link and this file does nothing.
 *
 * In 'api' mode, clicking the CTA opens the email/phone popup instead of
 * navigating; submitting it calls /vip-tattoo-plan/v1/create-checkout,
 * which opens a real Stripe Checkout Session or PayPal Order (whichever
 * provider + test/live keys are configured) and returns its hosted
 * checkout_url, to which we then navigate.
 */
(function () {
  if (window.VIP_TATTOO_PLAN_CHECKOUT_MODE !== 'api') return;

  var cta = document.getElementById('vtpCheckoutCta');
  var overlay = document.getElementById('vtpPopupOverlay');
  var card = document.getElementById('vtpPopupCard');
  var closeBtn = document.getElementById('vtpPopupClose');
  var form = document.getElementById('vtpCheckoutForm');
  var errorEl = document.getElementById('vtpPopupError');
  if (!cta || !overlay || !card || !form) return;

  function openPopup() {
    overlay.classList.add('is-open');
    card.classList.add('is-open');
    errorEl.hidden = true;
  }

  function closePopup() {
    overlay.classList.remove('is-open');
    card.classList.remove('is-open');
  }

  cta.addEventListener('click', function (e) {
    e.preventDefault();
    openPopup();
  });

  closeBtn.addEventListener('click', closePopup);
  overlay.addEventListener('click', closePopup);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var submitBtn = form.querySelector('button[type="submit"]');
    var email = form.email.value.trim();
    var phone = form.phone.value.trim();
    if (!email) return;

    submitBtn.disabled = true;
    errorEl.hidden = true;

    fetch(window.VIP_TATTOO_PLAN_REST_URL + 'create-checkout', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.VIP_TATTOO_PLAN_NONCE || ''
      },
      body: JSON.stringify({ email: email, phone: phone })
    })
      .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
      .then(function (result) {
        if (result.ok && result.data.checkout_url) {
          window.location.href = result.data.checkout_url;
        } else {
          throw new Error(result.data.error || 'Не вдалося відкрити оплату.');
        }
      })
      .catch(function (err) {
        submitBtn.disabled = false;
        errorEl.textContent = err.message || 'Помилка мережі. Спробуй ще раз.';
        errorEl.hidden = false;
      });
  });
})();
