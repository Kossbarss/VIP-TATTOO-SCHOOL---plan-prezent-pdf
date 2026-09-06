<?php
/**
 * Stripe + PayPal webhook listeners (test/sandbox and live credentials,
 * both kept saved side by side and switched by a mode radio).
 *
 * This site collects and stores NOTHING about its visitors: there is no
 * checkout form, no order table, no email/phone capture anywhere in this
 * plugin. The page has exactly two buttons -- one goes straight to the
 * Stripe Payment Link (or PayPal button) configured in "Оплата і кнопки"
 * in the main settings, the other straight to Victoria's Telegram. Both
 * providers handle the entire payment AND the post-payment redirect
 * themselves (configured in the Stripe/PayPal dashboard's own "after
 * payment" / return-URL settings, e.g. straight to a Telegram invite
 * link) -- none of that is this plugin's concern.
 *
 * The only reason this file exists at all is that the browser never
 * lands back on our own domain after paying, so the client-side Meta
 * Pixel loaded on the page never sees the purchase happen. A Stripe
 * Payment Link is itself a Checkout Session under the hood, and a PayPal
 * button still posts through that account's Orders API, so the SAME
 * account-wide webhook events (checkout.session.completed /
 * PAYMENT.CAPTURE.COMPLETED) fire for those payments exactly as they
 * would for anything else on the account. This file verifies those
 * webhook calls are genuinely from Stripe/PayPal, pulls the amount/
 * currency/email straight out of the event payload Stripe/PayPal already
 * sent, and immediately hashes+forwards it to Meta's Conversions API
 * (see includes/meta-capi.php) -- nothing from the payload is ever
 * written to the database. A tiny idempotency table records only an
 * opaque event id and a timestamp, purely to stop a Stripe/PayPal retry
 * from sending the same Purchase event to Meta twice.
 */

if (!defined('ABSPATH')) exit;

define('VIP_TATTOO_PLAN_WEBHOOK_LOG_TABLE', 'vip_tattoo_plan_webhook_events');

/* ------------------------------------------------------------------ */
/* Activation: create the idempotency table (no PII columns at all)   */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_payments_activate() {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_WEBHOOK_LOG_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider VARCHAR(20) NOT NULL,
        event_id VARCHAR(191) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY provider_event (provider, event_id)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(VIP_TATTOO_PLAN_PLUGIN_DIR . 'vip-tattoo-plan-viewer.php', 'vip_tattoo_plan_payments_activate');

define('VIP_TATTOO_PLAN_PAYMENTS_DB_VERSION', 2);
add_action('admin_init', function () {
    if ((int) get_option('vip_tattoo_plan_payments_db_version', 0) < VIP_TATTOO_PLAN_PAYMENTS_DB_VERSION) {
        vip_tattoo_plan_payments_activate();
        update_option('vip_tattoo_plan_payments_db_version', VIP_TATTOO_PLAN_PAYMENTS_DB_VERSION);
    }
});

// Idempotency check: true the first time this (provider, event_id) pair
// is seen, false on every repeat (Stripe/PayPal retry the same webhook
// delivery until it gets a 2xx, so this is the only thing standing
// between one purchase and Meta getting the same Purchase event twice).
function vip_tattoo_plan_webhook_seen_first_time($provider, $event_id) {
    global $wpdb;
    if (!$event_id) return true; // nothing to dedupe against -- let it through rather than silently drop it
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_WEBHOOK_LOG_TABLE;
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$table} (provider, event_id, created_at) VALUES (%s, %s, %s)",
        $provider, $event_id, current_time('mysql')
    ));
    return (bool) $inserted;
}

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_payment_fields() {
    return [
        'vip_tattoo_plan_stripe_mode'                => 'test',
        'vip_tattoo_plan_stripe_test_webhook_secret' => '',
        'vip_tattoo_plan_stripe_live_webhook_secret' => '',

        'vip_tattoo_plan_paypal_mode'                => 'sandbox',
        'vip_tattoo_plan_paypal_sandbox_client_id'   => '',
        'vip_tattoo_plan_paypal_sandbox_secret'      => '',
        'vip_tattoo_plan_paypal_sandbox_webhook_id'  => '',
        'vip_tattoo_plan_paypal_live_client_id'      => '',
        'vip_tattoo_plan_paypal_live_secret'         => '',
        'vip_tattoo_plan_paypal_live_webhook_id'     => '',
    ];
}

add_action('admin_menu', function () {
    add_menu_page(
        'VIP Tattoo План — Оплата',
        'VIP Tattoo План: Оплата',
        'manage_options',
        'vip-tattoo-plan-payments',
        'vip_tattoo_plan_render_payment_settings',
        'dashicons-money-alt'
    );
});

function vip_tattoo_plan_render_payment_settings() {
    if (!current_user_can('manage_options')) return;

    $defaults = vip_tattoo_plan_payment_fields();

    if (isset($_POST['vip_tattoo_plan_save_payment_settings']) && wp_verify_nonce($_POST['vip_tattoo_plan_payment_nonce'] ?? '', 'vip_tattoo_plan_payment_settings')) {
        foreach ($defaults as $key => $default) {
            update_option($key, isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default);
        }
        echo '<div class="notice notice-success"><p>Збережено.</p></div>';
    }

    $vals = [];
    foreach ($defaults as $key => $default) {
        $vals[$key] = get_option($key, $default);
    }

    $paypal_webhook_url = rest_url('vip-tattoo-plan/v1/paypal-webhook');
    $stripe_webhook_url = rest_url('vip-tattoo-plan/v1/stripe-webhook');
    ?>
    <div class="wrap">
        <h1>VIP Tattoo School — План курсу: Оплата</h1>
        <p>
            Цей сайт нічого не збирає і не зберігає про відвідувачів: жодної форми, жодної таблиці замовлень.
            На сторінці лише дві кнопки — одна веде на Stripe Payment Link / PayPal (налаштовується в «Оплата і кнопки» основних налаштувань),
            друга — напряму в Telegram до Вікторії. Обидва провайдери самі приймають оплату і самі ж роблять редирект після оплати
            (це налаштовується в кабінеті Stripe/PayPal, а не тут).
        </p>
        <p>
            Все нижче потрібне лише для одного: щоб <a href="<?php echo esc_url(admin_url('admin.php?page=vip-tattoo-plan-meta-capi')); ?>">Meta Conversions API</a>
            дізнавався про реальну оплату — бо після оплати браузер одразу летить у Telegram, а не назад на сайт, і клієнтський піксель Meta
            просто не встигає побачити подію. Дані з вебхука (сума, валюта, email/телефон, якщо провайдер їх передав) використовуються миттєво
            для хешування й відправки в Meta, і ніде не зберігаються.
        </p>

        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_payment_settings', 'vip_tattoo_plan_payment_nonce'); ?>

            <h2>Stripe</h2>
            <table class="form-table">
                <tr>
                    <th><label>Режим</label></th>
                    <td>
                        <label><input type="radio" name="vip_tattoo_plan_stripe_mode" value="test" <?php checked($vals['vip_tattoo_plan_stripe_mode'], 'test'); ?> /> Test</label><br />
                        <label><input type="radio" name="vip_tattoo_plan_stripe_mode" value="live" <?php checked($vals['vip_tattoo_plan_stripe_mode'], 'live'); ?> /> Live</label>
                        <p class="description">Перемикає, який з двох webhook-секретів нижче використовується для перевірки підпису — обидва завжди залишаються збереженими.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_test_webhook_secret">Test Webhook Signing Secret</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_stripe_test_webhook_secret" name="vip_tattoo_plan_stripe_test_webhook_secret" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_test_webhook_secret']); ?>" />
                        <p class="description">У Test mode: dashboard.stripe.com/test/webhooks → «Add destination» на URL нижче, подія <code>checkout.session.completed</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_live_webhook_secret">Live Webhook Signing Secret</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_stripe_live_webhook_secret" name="vip_tattoo_plan_stripe_live_webhook_secret" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_live_webhook_secret']); ?>" />
                        <p class="description">
                            dashboard.stripe.com (поза Test mode) → Developers → Webhooks → «Add destination». URL ендпоінта:<br />
                            <code><?php echo esc_html($stripe_webhook_url); ?></code><br />
                            Подія для підписки: <code>checkout.session.completed</code>. Після створення скопіюй «Signing secret» (<code>whsec_...</code>) сюди.
                        </p>
                    </td>
                </tr>
            </table>

            <h2>PayPal</h2>
            <table class="form-table">
                <tr>
                    <th><label>Режим</label></th>
                    <td>
                        <label><input type="radio" name="vip_tattoo_plan_paypal_mode" value="sandbox" <?php checked($vals['vip_tattoo_plan_paypal_mode'], 'sandbox'); ?> /> Sandbox</label><br />
                        <label><input type="radio" name="vip_tattoo_plan_paypal_mode" value="live" <?php checked($vals['vip_tattoo_plan_paypal_mode'], 'live'); ?> /> Live</label>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_paypal_sandbox_client_id">Sandbox Client ID</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_paypal_sandbox_client_id" name="vip_tattoo_plan_paypal_sandbox_client_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_paypal_sandbox_client_id']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_paypal_sandbox_secret">Sandbox Secret</label></th>
                    <td><input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_paypal_sandbox_secret" name="vip_tattoo_plan_paypal_sandbox_secret" value="<?php echo esc_attr($vals['vip_tattoo_plan_paypal_sandbox_secret']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_paypal_sandbox_webhook_id">Sandbox Webhook ID</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_paypal_sandbox_webhook_id" name="vip_tattoo_plan_paypal_sandbox_webhook_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_paypal_sandbox_webhook_id']); ?>" />
                        <p class="description">
                            developer.paypal.com (Sandbox) → твій застосунок → «Sandbox Webhooks» → «Add Webhook». URL ендпоінта:<br />
                            <code><?php echo esc_html($paypal_webhook_url); ?></code><br />
                            Подія для підписки: <code>Payment capture completed</code>. Після створення скопіюй «Webhook ID» сюди.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_paypal_live_client_id">Live Client ID</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_paypal_live_client_id" name="vip_tattoo_plan_paypal_live_client_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_paypal_live_client_id']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_paypal_live_secret">Live Secret</label></th>
                    <td><input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_paypal_live_secret" name="vip_tattoo_plan_paypal_live_secret" value="<?php echo esc_attr($vals['vip_tattoo_plan_paypal_live_secret']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_paypal_live_webhook_id">Live Webhook ID</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_paypal_live_webhook_id" name="vip_tattoo_plan_paypal_live_webhook_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_paypal_live_webhook_id']); ?>" />
                        <p class="description">URL ендпоінта (той самий для sandbox і live): <code><?php echo esc_html($paypal_webhook_url); ?></code></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="vip_tattoo_plan_save_payment_settings" value="1" class="button button-primary">Зберегти налаштування</button>
            </p>
        </form>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/* PayPal helpers (needed only to call verify-webhook-signature)       */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_paypal_mode() {
    return get_option('vip_tattoo_plan_paypal_mode', 'sandbox') === 'live' ? 'live' : 'sandbox';
}

function vip_tattoo_plan_paypal_api_base() {
    return vip_tattoo_plan_paypal_mode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function vip_tattoo_plan_paypal_client_id() {
    return vip_tattoo_plan_paypal_mode() === 'live'
        ? get_option('vip_tattoo_plan_paypal_live_client_id')
        : get_option('vip_tattoo_plan_paypal_sandbox_client_id');
}

function vip_tattoo_plan_paypal_secret() {
    return vip_tattoo_plan_paypal_mode() === 'live'
        ? get_option('vip_tattoo_plan_paypal_live_secret')
        : get_option('vip_tattoo_plan_paypal_sandbox_secret');
}

function vip_tattoo_plan_paypal_webhook_id() {
    return vip_tattoo_plan_paypal_mode() === 'live'
        ? get_option('vip_tattoo_plan_paypal_live_webhook_id')
        : get_option('vip_tattoo_plan_paypal_sandbox_webhook_id');
}

function vip_tattoo_plan_paypal_access_token() {
    $mode = vip_tattoo_plan_paypal_mode();
    $cache_key = 'vip_tattoo_plan_paypal_token_' . $mode;
    $cached = get_transient($cache_key);
    if ($cached) return $cached;

    $client_id = vip_tattoo_plan_paypal_client_id();
    $secret = vip_tattoo_plan_paypal_secret();
    if (!$client_id || !$secret) {
        return new WP_Error('no_credentials', 'PayPal Client ID/Secret не налаштовані для режиму "' . $mode . '".');
    }

    $response = wp_remote_post(vip_tattoo_plan_paypal_api_base() . '/v1/oauth2/token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body'    => ['grant_type' => 'client_credentials'],
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['access_token'])) {
        return new WP_Error('paypal_auth_error', $data['error_description'] ?? 'PayPal auth error');
    }

    $ttl = isset($data['expires_in']) ? max(60, (int) $data['expires_in'] - 60) : 300;
    set_transient($cache_key, $data['access_token'], $ttl);
    return $data['access_token'];
}

function vip_tattoo_plan_paypal_request($method, $path, $body = null) {
    $token = vip_tattoo_plan_paypal_access_token();
    if (is_wp_error($token)) return $token;

    $args = [
        'method'  => $method,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 20,
    ];
    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request(vip_tattoo_plan_paypal_api_base() . $path, $args);
    if (is_wp_error($response)) return $response;

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code >= 400) {
        $message = is_array($data) ? ($data['message'] ?? wp_remote_retrieve_body($response)) : wp_remote_retrieve_body($response);
        return new WP_Error('paypal_http_' . $code, 'PayPal HTTP ' . $code . ': ' . $message);
    }

    return $data;
}

function vip_tattoo_plan_verify_paypal_webhook(WP_REST_Request $request) {
    $webhook_id = vip_tattoo_plan_paypal_webhook_id();
    if (!$webhook_id) return false; // unconfigured -- refuse rather than blindly trust an unverifiable POST

    $body = [
        'transmission_id'   => $request->get_header('paypal-transmission-id'),
        'transmission_time' => $request->get_header('paypal-transmission-time'),
        'cert_url'          => $request->get_header('paypal-cert-url'),
        'auth_algo'         => $request->get_header('paypal-auth-algo'),
        'transmission_sig'  => $request->get_header('paypal-transmission-sig'),
        'webhook_id'        => $webhook_id,
        'webhook_event'     => json_decode($request->get_body(), true),
    ];

    $result = vip_tattoo_plan_paypal_request('POST', '/v1/notifications/verify-webhook-signature', $body);
    if (is_wp_error($result)) return false;
    return ($result['verification_status'] ?? '') === 'SUCCESS';
}

/* ------------------------------------------------------------------ */
/* Stripe helpers                                                      */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_stripe_mode() {
    return get_option('vip_tattoo_plan_stripe_mode', 'test') === 'live' ? 'live' : 'test';
}

function vip_tattoo_plan_stripe_webhook_secret() {
    return vip_tattoo_plan_stripe_mode() === 'live'
        ? get_option('vip_tattoo_plan_stripe_live_webhook_secret', '')
        : get_option('vip_tattoo_plan_stripe_test_webhook_secret', '');
}

// Stripe's own signature scheme (no SDK dependency): "Stripe-Signature"
// header is "t=<unix-timestamp>,v1=<hex-hmac>[,v0=...]" -- expected
// signature is HMAC-SHA256 of "{timestamp}.{raw request body}" keyed by
// the webhook signing secret.
function vip_tattoo_plan_verify_stripe_webhook(WP_REST_Request $request) {
    $webhook_secret = vip_tattoo_plan_stripe_webhook_secret();
    if (!$webhook_secret) return false; // unconfigured -- refuse rather than blindly trust an unverifiable POST

    $signature_header = $request->get_header('stripe-signature');
    if (!$signature_header) return false;

    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $signature_header) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) !== 2) continue;
        if ($pair[0] === 't') $timestamp = $pair[1];
        if ($pair[0] === 'v1') $signatures[] = $pair[1];
    }
    if (!$timestamp || !$signatures) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $request->get_body(), $webhook_secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) return true;
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* REST routes                                                         */
/* ------------------------------------------------------------------ */

add_action('rest_api_init', function () {
    register_rest_route('vip-tattoo-plan/v1', '/stripe-webhook', [
        'methods'             => 'POST',
        'callback'            => 'vip_tattoo_plan_rest_stripe_webhook',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('vip-tattoo-plan/v1', '/paypal-webhook', [
        'methods'             => 'POST',
        'callback'            => 'vip_tattoo_plan_rest_paypal_webhook',
        'permission_callback' => '__return_true',
    ]);
});

// Fires for EVERY completed Checkout Session on the Stripe account --
// including ones created by a Payment Link, which is what the site's
// own CTA button actually uses. Nothing here was created by this
// plugin; the session already carries whatever Stripe itself collected
// (amount, currency, and the buyer's email if Stripe asked for one).
function vip_tattoo_plan_rest_stripe_webhook(WP_REST_Request $request) {
    if (!vip_tattoo_plan_verify_stripe_webhook($request)) {
        return new WP_REST_Response(['error' => 'Invalid signature'], 400);
    }

    $event = json_decode($request->get_body(), true);
    if (($event['type'] ?? '') !== 'checkout.session.completed') {
        return new WP_REST_Response(['received' => true], 200);
    }

    $session = $event['data']['object'] ?? [];
    if (($session['payment_status'] ?? '') !== 'paid') {
        return new WP_REST_Response(['received' => true], 200);
    }

    $event_id = $event['id'] ?? ($session['id'] ?? '');
    if (!vip_tattoo_plan_webhook_seen_first_time('stripe', $event_id)) {
        return new WP_REST_Response(['received' => true, 'duplicate' => true], 200);
    }

    do_action('vip_tattoo_plan_purchase_confirmed', [
        'event_id' => $session['id'] ?? $event_id,
        'email'    => $session['customer_details']['email'] ?? '',
        'phone'    => $session['customer_details']['phone'] ?? '',
        'value'    => isset($session['amount_total']) ? $session['amount_total'] / 100 : null,
        'currency' => $session['currency'] ?? null,
    ]);

    return new WP_REST_Response(['received' => true], 200);
}

// Same idea for PayPal: fires for every completed capture on the
// account, whichever button/flow the payment actually came through.
function vip_tattoo_plan_rest_paypal_webhook(WP_REST_Request $request) {
    if (!vip_tattoo_plan_verify_paypal_webhook($request)) {
        return new WP_REST_Response(['error' => 'Invalid signature'], 400);
    }

    $event = json_decode($request->get_body(), true);
    if (($event['event_type'] ?? '') !== 'PAYMENT.CAPTURE.COMPLETED') {
        return new WP_REST_Response(['received' => true], 200);
    }

    $resource = $event['resource'] ?? [];
    $event_id = $event['id'] ?? ($resource['id'] ?? '');
    if (!vip_tattoo_plan_webhook_seen_first_time('paypal', $event_id)) {
        return new WP_REST_Response(['received' => true, 'duplicate' => true], 200);
    }

    $payer_email = $resource['payer']['email_address'] ?? ($event['resource']['payee']['email_address'] ?? '');

    do_action('vip_tattoo_plan_purchase_confirmed', [
        'event_id' => $resource['id'] ?? $event_id,
        'email'    => $payer_email,
        'phone'    => '',
        'value'    => isset($resource['amount']['value']) ? (float) $resource['amount']['value'] : null,
        'currency' => $resource['amount']['currency_code'] ?? null,
    ]);

    return new WP_REST_Response(['received' => true], 200);
}
