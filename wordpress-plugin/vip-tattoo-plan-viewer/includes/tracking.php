<?php
/**
 * View/click event tracking for the plan-viewer page (the 23-slide course
 * presentation).
 *
 * Flow: js/track.js fires once per page load ("view") and once per click
 * on the Stripe/Telegram buttons ("checkout_click" / "contact_click"),
 * each tagged with a per-page-load token generated client-side -> REST
 * route validates the WP REST nonce (first-party only, same origin) and
 * inserts the event, de-duplicated on (visit_token, event_type) so a
 * retried/duplicated front-end call can never double-count -> on a fresh
 * insert we fan the event out to: an optional outbound webhook
 * (HMAC-signed, for Zapier/Make/a CRM), a WordPress action hook for
 * same-site extensions, and an optional Telegram admin notification on
 * "checkout_click".
 */

if (!defined('ABSPATH')) exit;

define('VIP_TATTOO_PLAN_EVENTS_TABLE', 'vip_tattoo_plan_events');

/* ------------------------------------------------------------------ */
/* Activation: create the events table                                */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_tracking_activate() {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_EVENTS_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        visit_token VARCHAR(64) NOT NULL,
        event_type VARCHAR(20) NOT NULL,
        utm_source VARCHAR(191) DEFAULT NULL,
        utm_medium VARCHAR(191) DEFAULT NULL,
        utm_campaign VARCHAR(191) DEFAULT NULL,
        referrer VARCHAR(512) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY visit_event (visit_token, event_type)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Shared secret used to HMAC-sign the outbound webhook body, so the
    // receiving end (Zapier/Make/a CRM) can verify a payload genuinely
    // came from this site -- same "sign the payload" idea as the landing
    // plugin's Stripe/PayPal/Telegram signature checks, just outbound
    // instead of inbound.
    if (!get_option('vip_tattoo_plan_webhook_secret')) {
        update_option('vip_tattoo_plan_webhook_secret', wp_generate_password(32, false));
    }
}
register_activation_hook(VIP_TATTOO_PLAN_PLUGIN_DIR . 'vip-tattoo-plan-viewer.php', 'vip_tattoo_plan_tracking_activate');

// Same in-place-upgrade safety net the landing plugin uses: activation
// hooks don't re-fire on a plain file overwrite, so bump this whenever
// the CREATE TABLE SQL above changes.
define('VIP_TATTOO_PLAN_DB_VERSION', 1);
add_action('admin_init', function () {
    if ((int) get_option('vip_tattoo_plan_db_version', 0) < VIP_TATTOO_PLAN_DB_VERSION) {
        vip_tattoo_plan_tracking_activate();
        update_option('vip_tattoo_plan_db_version', VIP_TATTOO_PLAN_DB_VERSION);
    }
});

/* ------------------------------------------------------------------ */
/* REST route                                                          */
/* ------------------------------------------------------------------ */

add_action('rest_api_init', function () {
    register_rest_route('vip-tattoo-plan/v1', '/track', [
        'methods'             => 'POST',
        'callback'            => 'vip_tattoo_plan_rest_track',
        // First-party only: the front-end sends the standard WP REST
        // nonce (window.VIP_TATTOO_PLAN_NONCE, printed same-request by
        // vip_tattoo_plan_render_globals()), so a page load is required
        // before any event can be recorded -- a bare cross-site POST
        // without that nonce is rejected here rather than in the
        // callback, matching how the rest of this codebase gates access
        // at permission_callback rather than deep inside the handler.
        'permission_callback' => function (WP_REST_Request $request) {
            $nonce = $request->get_header('x-wp-nonce') ?: $request->get_param('_wpnonce');
            return (bool) wp_verify_nonce($nonce, 'wp_rest');
        },
    ]);
});

function vip_tattoo_plan_rest_track(WP_REST_Request $request) {
    global $wpdb;

    $visit_token = sanitize_text_field($request->get_param('visit_token'));
    $event_type  = sanitize_key($request->get_param('event_type'));

    $allowed_events = ['view', 'checkout_click', 'contact_click'];
    if (!$visit_token || !in_array($event_type, $allowed_events, true)) {
        return new WP_REST_Response(['error' => 'Invalid payload'], 400);
    }

    $data = [
        'visit_token'  => $visit_token,
        'event_type'   => $event_type,
        'utm_source'   => sanitize_text_field($request->get_param('utm_source')),
        'utm_medium'   => sanitize_text_field($request->get_param('utm_medium')),
        'utm_campaign' => sanitize_text_field($request->get_param('utm_campaign')),
        'referrer'     => esc_url_raw($request->get_param('referrer')),
        'created_at'   => current_time('mysql'),
    ];

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_EVENTS_TABLE;

    // Idempotent insert: the UNIQUE KEY on (visit_token, event_type) makes
    // a retried/duplicated client call a silent no-op instead of a second
    // row, same guarantee the payments table gives the order flow.
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$table} (visit_token, event_type, utm_source, utm_medium, utm_campaign, referrer, created_at)
         VALUES (%s, %s, %s, %s, %s, %s, %s)",
        $data['visit_token'], $data['event_type'], $data['utm_source'],
        $data['utm_medium'], $data['utm_campaign'], $data['referrer'], $data['created_at']
    ));

    // Only fan the event out on a genuinely new row -- a de-duplicated
    // repeat must never re-fire the webhook/Telegram notification.
    if ($inserted) {
        do_action('vip_tattoo_plan_event', $data);
        vip_tattoo_plan_dispatch_webhook($data);
        if ($event_type === 'checkout_click') {
            vip_tattoo_plan_notify_telegram($data);
        }
    }

    return new WP_REST_Response(['ok' => true], 200);
}

/* ------------------------------------------------------------------ */
/* Outbound webhook                                                    */
/* ------------------------------------------------------------------ */

// Fire-and-forget POST to whatever URL is configured (Zapier, Make,
// a CRM's inbound webhook, ...), HMAC-signed the same way Stripe signs
// its own outbound webhooks so the receiving end can verify authenticity:
// header "X-VIP-Tattoo-Signature: sha256=<hex hmac of the raw JSON body>".
function vip_tattoo_plan_dispatch_webhook($data) {
    $url = trim(get_option('vip_tattoo_plan_webhook_url', ''));
    if (!$url) return;

    $body = wp_json_encode([
        'event'     => $data['event_type'],
        'token'     => $data['visit_token'],
        'utm'       => [
            'source'   => $data['utm_source'],
            'medium'   => $data['utm_medium'],
            'campaign' => $data['utm_campaign'],
        ],
        'referrer'  => $data['referrer'],
        'page_url'  => vip_tattoo_plan_page_url(),
        'timestamp' => $data['created_at'],
    ]);

    $secret = get_option('vip_tattoo_plan_webhook_secret', '');
    $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type'              => 'application/json',
            'X-VIP-Tattoo-Signature'    => $signature,
        ],
        'body'    => $body,
        'timeout' => 10,
        'blocking' => false, // fire-and-forget -- never make a visitor's request wait on a third party
    ]);

    if (is_wp_error($response)) {
        error_log('[VIP Tattoo Plan] Webhook dispatch failed: ' . $response->get_error_message());
    }
}

/* ------------------------------------------------------------------ */
/* Optional Telegram admin notification                                */
/* ------------------------------------------------------------------ */

// Deliberately its own bot token/chat id, independent of the landing
// plugin's Telegram settings -- the two plugins stay decoupled per the
// instructions this was cloned under, so disabling/removing one never
// breaks the other.
function vip_tattoo_plan_notify_telegram($data) {
    $token = get_option('vip_tattoo_plan_telegram_bot_token', '');
    $chat_id = get_option('vip_tattoo_plan_telegram_chat_id', '');
    if (!$token || !$chat_id) return;

    $labels = [
        'checkout_click' => '💳 Клик по «Открыть доступ к обучению» на странице плана курса',
    ];
    $message = ($labels[$data['event_type']] ?? '🔔 Событие на странице плана курсу') . "\n"
        . "UTM: " . ($data['utm_source'] ?: '—') . ' / ' . ($data['utm_medium'] ?: '—') . ' / ' . ($data['utm_campaign'] ?: '—') . "\n"
        . 'Реферер: ' . ($data['referrer'] ?: '—');

    wp_remote_post("https://api.telegram.org/bot{$token}/sendMessage", [
        'body'     => ['chat_id' => $chat_id, 'text' => $message],
        'timeout'  => 10,
        'blocking' => false,
    ]);
}

/* ------------------------------------------------------------------ */
/* Admin: recent events table (mirrors the landing plugin's "Останні    */
/* замовлення" list)                                                   */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_render_recent_events() {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_EVENTS_TABLE;
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 25");
    if (!$rows) {
        echo '<p>Поки що немає подій.</p>';
        return;
    }
    $event_labels = [
        'view'            => '👁️ Перегляд',
        'checkout_click'  => '💳 Клік «Оплатити»',
        'contact_click'   => '✉️ Клік «Написати»',
    ];
    echo '<table class="widefat striped"><thead><tr><th>Подія</th><th>UTM Source</th><th>UTM Campaign</th><th>Реферер</th><th>Час</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        printf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html($event_labels[$row->event_type] ?? $row->event_type),
            esc_html($row->utm_source ?: '—'),
            esc_html($row->utm_campaign ?: '—'),
            esc_html($row->referrer ?: '—'),
            esc_html($row->created_at)
        );
    }
    echo '</tbody></table>';
}
