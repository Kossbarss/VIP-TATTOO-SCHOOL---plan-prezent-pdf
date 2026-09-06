<?php
/**
 * Meta Conversions API: sends the "Purchase" event server-side straight
 * to Meta the moment Stripe/PayPal confirms a real payment, hooked on
 * 'vip_tattoo_plan_purchase_confirmed' (fired from includes/payments.php
 * for any completed Stripe Checkout Session or PayPal capture on the
 * account -- including the ones the site's own Payment Link / PayPal
 * button produce, since this plugin never creates its own checkout).
 *
 * This exists because the buyer's browser never lands back on our own
 * domain after paying -- Stripe/PayPal's own "after payment" redirect
 * goes straight to Telegram -- so the client-side Meta Pixel loaded on
 * the plan page never sees the purchase happen. The Conversions API call
 * below is the only place this event can come from.
 *
 * Nothing here is stored: the event payload (email/phone/amount, exactly
 * what Stripe/PayPal already collected on their own hosted page) is read
 * once from the hook argument, hashed, sent to Meta, and discarded --
 * this file writes only a status line (no PII) to its own log table.
 */

if (!defined('ABSPATH')) exit;

define('VIP_TATTOO_PLAN_META_CAPI_LOG_TABLE', 'vip_tattoo_plan_meta_capi_log');

/* ------------------------------------------------------------------ */
/* Activation: create the send-attempts log table                     */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_meta_capi_activate() {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_META_CAPI_LOG_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_token VARCHAR(64) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'error',
        message TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(VIP_TATTOO_PLAN_PLUGIN_DIR . 'vip-tattoo-plan-viewer.php', 'vip_tattoo_plan_meta_capi_activate');

define('VIP_TATTOO_PLAN_META_CAPI_DB_VERSION', 1);
add_action('admin_init', function () {
    if ((int) get_option('vip_tattoo_plan_meta_capi_db_version', 0) < VIP_TATTOO_PLAN_META_CAPI_DB_VERSION) {
        vip_tattoo_plan_meta_capi_activate();
        update_option('vip_tattoo_plan_meta_capi_db_version', VIP_TATTOO_PLAN_META_CAPI_DB_VERSION);
    }
});

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_meta_capi_fields() {
    return [
        'vip_tattoo_plan_meta_capi_pixel_id'         => '',
        'vip_tattoo_plan_meta_capi_access_token'     => '',
        'vip_tattoo_plan_meta_capi_test_event_code'  => '',
    ];
}

add_action('admin_menu', function () {
    add_submenu_page(
        'vip-tattoo-plan-payments',
        'VIP Tattoo План — Meta Conversions API',
        'Meta Conversions API',
        'manage_options',
        'vip-tattoo-plan-meta-capi',
        'vip_tattoo_plan_render_meta_capi_settings'
    );
});

function vip_tattoo_plan_render_meta_capi_settings() {
    if (!current_user_can('manage_options')) return;

    $defaults = vip_tattoo_plan_meta_capi_fields();

    if (isset($_POST['vip_tattoo_plan_save_meta_capi_settings']) && wp_verify_nonce($_POST['vip_tattoo_plan_meta_capi_nonce'] ?? '', 'vip_tattoo_plan_meta_capi_settings')) {
        foreach ($defaults as $key => $default) {
            update_option($key, isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default);
        }
        echo '<div class="notice notice-success"><p>Збережено.</p></div>';
    }

    $vals = [];
    foreach ($defaults as $key => $default) {
        $vals[$key] = get_option($key, $default);
    }
    ?>
    <div class="wrap">
        <h1>VIP Tattoo School — План курсу: Meta Conversions API</h1>
        <p>
            Надсилає подію <code>Purchase</code> напряму в Meta, коли Stripe/PayPal підтверджує оплату
            (клієнтський піксель цю подію ніколи не бачить, бо після оплати Stripe/PayPal одразу веде в Telegram, а не на сторінку сайту).
        </p>
        <ol>
            <li>Events Manager → обраний піксель → Настройки → Conversions API → «Сгенерировать маркер доступа» (без Dataset Quality API).</li>
            <li>Встав токен у поле нижче.</li>
            <li>Для перевірки: Events Manager → Тестирование событий, скопіюй «Код тестового события» і встав у поле нижче — після тесту не забудь очистити це поле, інакше реальні продажі теж підуть у тестовий режим.</li>
        </ol>

        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_meta_capi_settings', 'vip_tattoo_plan_meta_capi_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_meta_capi_pixel_id">Pixel ID</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_meta_capi_pixel_id" name="vip_tattoo_plan_meta_capi_pixel_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_meta_capi_pixel_id']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_meta_capi_access_token">Access Token (Conversions API)</label></th>
                    <td><input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_meta_capi_access_token" name="vip_tattoo_plan_meta_capi_access_token" value="<?php echo esc_attr($vals['vip_tattoo_plan_meta_capi_access_token']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_meta_capi_test_event_code">Test Event Code (опційно)</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_meta_capi_test_event_code" name="vip_tattoo_plan_meta_capi_test_event_code" value="<?php echo esc_attr($vals['vip_tattoo_plan_meta_capi_test_event_code']); ?>" />
                        <p class="description">Заповнюй тільки на час перевірки в «Тестирование событий» — потім видали значення.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="vip_tattoo_plan_save_meta_capi_settings" value="1" class="button button-primary">Зберегти налаштування</button>
            </p>
        </form>

        <hr />
        <h2>Останні спроби відправки</h2>
        <?php vip_tattoo_plan_render_meta_capi_log(); ?>
    </div>
    <?php
}

function vip_tattoo_plan_render_meta_capi_log() {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_META_CAPI_LOG_TABLE;
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 25");
    if (!$rows) {
        echo '<p>Поки що немає спроб відправки.</p>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>Токен замовлення</th><th>Статус</th><th>Повідомлення</th><th>Коли</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        printf(
            '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
            esc_html($row->order_token ?: '—'),
            $row->status === 'success' ? '✓ успішно' : '✗ помилка',
            esc_html($row->message ?: '—'),
            esc_html($row->created_at)
        );
    }
    echo '</tbody></table>';
}

/* ------------------------------------------------------------------ */
/* Sending the Purchase event                                          */
/* ------------------------------------------------------------------ */

// Meta requires PII fields (email/phone) sent as lowercased, trimmed
// SHA-256 hashes -- raw values never leave the server, only their hash.
function vip_tattoo_plan_meta_capi_hash($value) {
    return $value ? hash('sha256', strtolower(trim($value))) : null;
}

// $data comes straight from includes/payments.php's webhook handlers:
// ['event_id' => Stripe session id / PayPal capture id, 'email', 'phone',
// 'value', 'currency'] -- whatever Stripe/PayPal themselves collected and
// put in their own webhook payload. None of it was collected by this
// plugin, and none of it is stored anywhere beyond this function call.
add_action('vip_tattoo_plan_purchase_confirmed', function ($data) {
    $pixel_id = trim(get_option('vip_tattoo_plan_meta_capi_pixel_id', ''));
    $access_token = trim(get_option('vip_tattoo_plan_meta_capi_access_token', ''));
    if (!$pixel_id || !$access_token) return; // not configured -- silently skip, same as the webhook verifiers elsewhere in this plugin

    $user_data = array_filter([
        'em' => vip_tattoo_plan_meta_capi_hash($data['email'] ?? ''),
        'ph' => vip_tattoo_plan_meta_capi_hash(preg_replace('/\D/', '', $data['phone'] ?? '')),
    ]);

    $event = [
        'event_name'       => 'Purchase',
        'event_time'       => time(),
        'action_source'    => 'website',
        'event_source_url' => vip_tattoo_plan_page_url(),
        'user_data'        => $user_data,
        'custom_data'      => [
            'value'    => $data['value'] !== null ? (float) $data['value'] : 0,
            'currency' => strtolower($data['currency'] ?: 'eur'),
        ],
    ];
    if (!empty($data['event_id'])) {
        $event['event_id'] = $data['event_id']; // dedupe key, matches the Stripe session / PayPal capture id
    }

    $body = [
        'data'          => wp_json_encode([$event]),
        'access_token'  => $access_token,
    ];
    $test_event_code = trim(get_option('vip_tattoo_plan_meta_capi_test_event_code', ''));
    if ($test_event_code) {
        $body['test_event_code'] = $test_event_code;
    }

    $response = wp_remote_post("https://graph.facebook.com/v18.0/{$pixel_id}/events", [
        'body'    => $body,
        'timeout' => 15,
    ]);

    global $wpdb;
    $log_table = $wpdb->prefix . VIP_TATTOO_PLAN_META_CAPI_LOG_TABLE;

    if (is_wp_error($response)) {
        $wpdb->insert($log_table, [
            'order_token' => $data['event_id'] ?? null,
            'status'      => 'error',
            'message'     => $response->get_error_message(),
            'created_at'  => current_time('mysql'),
        ]);
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $wpdb->insert($log_table, [
        'order_token' => $data['event_id'] ?? null,
        'status'      => $code < 400 ? 'success' : 'error',
        'message'     => $raw_body,
        'created_at'  => current_time('mysql'),
    ]);

    if ($code >= 400) {
        error_log('[VIP Tattoo Plan] Meta CAPI Purchase send failed (' . $code . '): ' . $raw_body);
    }
});
