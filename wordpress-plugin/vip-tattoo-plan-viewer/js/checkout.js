/**
 * No form, no popup: clicking either payment button calls
 * /vip-tattoo-plan/v1/create-checkout directly (with plan_type=full or
 * plan_type=installment) and redirects to whatever Stripe Checkout Session
 * or PayPal Order/Subscription URL comes back. This site never asks the
 * visitor for an email or phone -- if the active provider's hosted page
 * happens to collect one, that only ever reaches our server later via its
 * webhook (see includes/payments.php and includes/installments.php),
 * never through this click.
 */
(function () {
  var buttons = [
    document.getElementById('vtpCheckoutCta'),
    document.getElementById('vtpCheckoutCtaInstallment')
  ].filter(Boolean);

  buttons.forEach(function (cta) {
    var originalLabel = cta.innerHTML;
    var planType = cta.getAttribute('data-plan-type') || 'full';

    cta.addEventListener('click', function () {
      if (cta.disabled) return;
      cta.disabled = true;
      cta.innerHTML = '<span>Загрузка...</span>';

      fetch(window.VIP_TATTOO_PLAN_REST_URL + 'create-checkout', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.VIP_TATTOO_PLAN_NONCE || ''
        },
        body: JSON.stringify({ plan_type: planType })
      })
        .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
        .then(function (result) {
          if (result.ok && result.data.checkout_url) {
            window.location.href = result.data.checkout_url;
          } else {
            throw new Error(result.data.error || 'Не удалось открыть оплату.');
          }
        })
        .catch(function (err) {
          cta.disabled = false;
          cta.innerHTML = originalLabel;
          alert(err.message || 'Ошибка сети. Попробуй ещё раз.');
        });
    });
  });
})();
