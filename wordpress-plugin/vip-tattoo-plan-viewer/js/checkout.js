/**
 * No form, no popup: clicking "Открыть доступ к обучению" calls
 * /vip-tattoo-plan/v1/create-checkout directly and redirects to whatever
 * Stripe Checkout Session or PayPal Order URL comes back. This site never
 * asks the visitor for an email or phone -- if the active provider's
 * hosted page happens to collect one, that only ever reaches our server
 * later via its webhook (see includes/payments.php), never through this
 * click.
 */
(function () {
  var cta = document.getElementById('vtpCheckoutCta');
  if (!cta) return;

  var originalLabel = cta.innerHTML;

  cta.addEventListener('click', function () {
    if (cta.disabled) return;
    cta.disabled = true;
    cta.innerHTML = '<span>Завантаження…</span>';

    fetch(window.VIP_TATTOO_PLAN_REST_URL + 'create-checkout', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.VIP_TATTOO_PLAN_NONCE || ''
      }
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
        cta.disabled = false;
        cta.innerHTML = originalLabel;
        alert(err.message || 'Помилка мережі. Спробуй ще раз.');
      });
  });
})();
