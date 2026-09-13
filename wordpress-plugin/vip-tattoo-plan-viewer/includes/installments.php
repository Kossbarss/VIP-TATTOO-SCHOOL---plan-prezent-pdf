<?php
/**
 * Оплата частинами на 2 платежі (137.50 EUR кожен, раз на тиждень) —
 * той самий товар, другий спосіб оплати поруч із повною оплатою.
 *
 * Stripe: Checkout Session у режимі subscription на тижневу ціну,
 * одразу після checkout.session.completed підписка перетворюється на
 * Subscription Schedule з iterations=2, end_behavior=cancel — Stripe сам
 * зупиняє її після 2-го платежу.
 *
 * PayPal: Billing Plan із total_cycles=2 (створюється один раз через
 * кнопку в адмінці), далі звичайна Subscription на цей план.
 *
 * Після кожної події (оплата кроку 1/2, невдала спроба, фінал) — запис
 * у Google Sheets (без email/CRM полів на цьому сайті — це окрема
 * таблиця, у яку йде лише те, що клієнт сам лишив платіжному провайдеру)
 * та email через Gmail SMTP. Невдалий 2-й платіж => попередження і
 * видалення з груп курсу через 24 год, якщо картку не оновили.
 */

if (!defined('ABSPATH')) exit;

define('VIP_TATTOO_PLAN_INSTALLMENT_STEP_CENTS', 13750);
define('VIP_TATTOO_PLAN_INSTALLMENT_TOTAL_CENTS', 27500);

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_installment_fields() {
    return [
        'vip_tattoo_plan_stripe_test_installment_price_id' => '',
        'vip_tattoo_plan_stripe_live_installment_price_id' => '',
        'vip_tattoo_plan_stripe_test_webhook_secret_installment' => '',
        'vip_tattoo_plan_stripe_live_webhook_secret_installment' => '',

        'vip_tattoo_plan_paypal_sandbox_installment_plan_id' => '',
        'vip_tattoo_plan_paypal_live_installment_plan_id'    => '',

        'vip_tattoo_plan_smtp_host'      => 'smtp.gmail.com',
        'vip_tattoo_plan_smtp_port'      => '587',
        'vip_tattoo_plan_smtp_username'  => '',
        'vip_tattoo_plan_smtp_password'  => '',
        'vip_tattoo_plan_smtp_from_email' => '',
        'vip_tattoo_plan_smtp_from_name'  => 'VIP Tattoo School',

        'vip_tattoo_plan_google_sheet_id' => '',

        'vip_tattoo_plan_installment_telegram_bot_token'    => '',
        'vip_tattoo_plan_installment_telegram_bot_username' => '',
        'vip_tattoo_plan_installment_kick_group_ids'        => '-1003753289762,-1003954532258',
        'vip_tattoo_plan_installment_kick_delay_hours'      => '24',
    ];
}

add_action('admin_menu', function () {
    add_submenu_page(
        'vip-tattoo-plan-payments',
        'Оплата частинами',
        'Оплата частинами',
        'manage_options',
        'vip-tattoo-plan-installments',
        'vip_tattoo_plan_render_installment_settings'
    );
});

function vip_tattoo_plan_render_installment_settings() {
    if (!current_user_can('manage_options')) return;

    $defaults = vip_tattoo_plan_installment_fields();

    if (isset($_POST['vip_tattoo_plan_save_installment_settings']) && wp_verify_nonce($_POST['vip_tattoo_plan_installment_nonce'] ?? '', 'vip_tattoo_plan_installment_settings')) {
        foreach ($defaults as $key => $default) {
            update_option($key, isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default);
        }
        echo '<div class="notice notice-success"><p>Збережено.</p></div>';
    }

    if (isset($_POST['vip_tattoo_plan_create_paypal_plan']) && wp_verify_nonce($_POST['vip_tattoo_plan_installment_nonce'] ?? '', 'vip_tattoo_plan_installment_settings')) {
        $result = vip_tattoo_plan_paypal_create_installment_plan();
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Помилка: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>PayPal Billing Plan створено: <code>' . esc_html($result) . '</code> — вже збережено в поле нижче.</p></div>';
        }
    }

    if (isset($_POST['vip_tattoo_plan_set_installment_telegram_webhook']) && wp_verify_nonce($_POST['vip_tattoo_plan_installment_nonce'] ?? '', 'vip_tattoo_plan_installment_settings')) {
        $result = vip_tattoo_plan_set_installment_telegram_webhook();
        if ($result === true) {
            echo '<div class="notice notice-success"><p>Telegram webhook (оплати частинами) встановлено успішно.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Помилка встановлення webhook: ' . esc_html($result) . '</p></div>';
        }
    }

    if (isset($_POST['vip_tattoo_plan_test_sheet_row']) && wp_verify_nonce($_POST['vip_tattoo_plan_installment_nonce'] ?? '', 'vip_tattoo_plan_installment_settings')) {
        $result = vip_tattoo_plan_sheets_append_row([
            '', current_time('mysql'), '', '', 'Тест', '', '', '', '', '', 'Тестовий рядок з адмінки', '', '', '', current_time('mysql'),
        ]);
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Помилка запису в Google Sheets: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Тестовий рядок додано в таблицю.</p></div>';
        }
    }

    $vals = [];
    foreach ($defaults as $key => $default) {
        $vals[$key] = get_option($key, $default);
    }

    $sa_email = vip_tattoo_plan_google_service_account_email();
    $installment_telegram_webhook_url = rest_url('vip-tattoo-plan/v1/installment-telegram-webhook');
    ?>
    <div class="wrap">
        <h1>Оплата частинами — 2 платежі по 137.50€</h1>
        <p class="description">Той самий товар (курс), другий спосіб оплати поруч із повною оплатою 275€ одразу. Провайдер (Stripe/PayPal) і базова ціна беруться з основної сторінки «VIP Tattoo План: Оплата».</p>

        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_installment_settings', 'vip_tattoo_plan_installment_nonce'); ?>

            <h2>Stripe — тижнева ціна (137.50 EUR, interval: week)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_test_installment_price_id">Test Price ID (рекурентний)</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_stripe_test_installment_price_id" name="vip_tattoo_plan_stripe_test_installment_price_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_test_installment_price_id']); ?>" placeholder="price_..." />
                        <p class="description">Створи в dashboard.stripe.com (Test mode) → Product catalog → New product → ціна 137.50 EUR, Recurring, Weekly. Встав сюди Price ID.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_live_installment_price_id">Live Price ID (рекурентний)</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_stripe_live_installment_price_id" name="vip_tattoo_plan_stripe_live_installment_price_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_live_installment_price_id']); ?>" placeholder="price_..." />
                        <p class="description">Те саме, але в Live mode. Плагін сам після 1-го платежу обмежує підписку до 2 циклів і скасовує її — окремо нічого налаштовувати в Stripe не потрібно.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Вебхуки Stripe</label></th>
                    <td><p class="description">Можна або додати ці 3 події до того самого Stripe webhook endpoint, що і для повної оплати (сторінка «Оплата» вище), або створити для них окремий пункт призначення з тим самим URL — обидва варіанти працюють. Потрібні події: <code>invoice.payment_succeeded</code>, <code>invoice.payment_failed</code>, <code>customer.subscription.deleted</code>.</p></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_test_webhook_secret_installment">Test Signing secret (окремий вебхук)</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_stripe_test_webhook_secret_installment" name="vip_tattoo_plan_stripe_test_webhook_secret_installment" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_test_webhook_secret_installment']); ?>" placeholder="whsec_..." />
                        <p class="description">Заповнюй лише якщо створив ОКРЕМИЙ пункт призначення для цих 3 подій (у нього свій "Секрет підписання"/Signing secret, відмінний від основного вебхука). Якщо додав ці події до вже існуючого вебхука — залиш порожнім.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_live_webhook_secret_installment">Live Signing secret (окремий вебхук)</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_stripe_live_webhook_secret_installment" name="vip_tattoo_plan_stripe_live_webhook_secret_installment" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_live_webhook_secret_installment']); ?>" placeholder="whsec_..." />
                        <p class="description">Те саме, але для Live mode окремого пункту призначення.</p>
                    </td>
                </tr>
            </table>

            <h2>PayPal — Billing Plan (137.50 EUR × 2, тиждень)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_paypal_sandbox_installment_plan_id">Sandbox Plan ID</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_paypal_sandbox_installment_plan_id" name="vip_tattoo_plan_paypal_sandbox_installment_plan_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_paypal_sandbox_installment_plan_id']); ?>" placeholder="P-..." /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_paypal_live_installment_plan_id">Live Plan ID</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_paypal_live_installment_plan_id" name="vip_tattoo_plan_paypal_live_installment_plan_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_paypal_live_installment_plan_id']); ?>" placeholder="P-..." /></td>
                </tr>
                <tr>
                    <th><label>Створити план автоматично</label></th>
                    <td><p class="description">Спочатку заповни й збережи Sandbox/Live Client ID + Secret на сторінці «Оплата», обери потрібний режим (PayPal → Режим), збережи — тоді натисни кнопку нижче, вона створить продукт і план на 2 цикли по 137.50 EUR/тиждень і сама впише Plan ID у відповідне поле вище.</p></td>
                </tr>
            </table>

            <h2>Email-сповіщення (Gmail SMTP)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_smtp_host">SMTP Host</label></th>
                    <td><input type="text" id="vip_tattoo_plan_smtp_host" name="vip_tattoo_plan_smtp_host" value="<?php echo esc_attr($vals['vip_tattoo_plan_smtp_host']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_smtp_port">SMTP Port</label></th>
                    <td><input type="number" id="vip_tattoo_plan_smtp_port" name="vip_tattoo_plan_smtp_port" value="<?php echo esc_attr($vals['vip_tattoo_plan_smtp_port']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_smtp_username">SMTP логін (Gmail-адреса)</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_smtp_username" name="vip_tattoo_plan_smtp_username" value="<?php echo esc_attr($vals['vip_tattoo_plan_smtp_username']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_smtp_password">SMTP пароль (App Password)</label></th>
                    <td><input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_smtp_password" name="vip_tattoo_plan_smtp_password" value="<?php echo esc_attr($vals['vip_tattoo_plan_smtp_password']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_smtp_from_email">Email відправника (From)</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_smtp_from_email" name="vip_tattoo_plan_smtp_from_email" value="<?php echo esc_attr($vals['vip_tattoo_plan_smtp_from_email']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_smtp_from_name">Ім'я відправника (From)</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_smtp_from_name" name="vip_tattoo_plan_smtp_from_name" value="<?php echo esc_attr($vals['vip_tattoo_plan_smtp_from_name']); ?>" /></td>
                </tr>
            </table>

            <h2>Google Sheets (журнал платежів оплати частинами)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_google_sheet_id">ID таблиці</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_google_sheet_id" name="vip_tattoo_plan_google_sheet_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_google_sheet_id']); ?>" />
                        <p class="description">Частина URL таблиці між <code>/d/</code> і <code>/edit</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Service Account</label></th>
                    <td>
                        <?php if ($sa_email): ?>
                            <p><code><?php echo esc_html($sa_email); ?></code> — переконайся, що цей email доданий як "Редактор" у таблиці (Поділитися).</p>
                        <?php else: ?>
                            <p class="description">Файл ключа <code>config/google-service-account.json</code> не знайдено в папці плагіна.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2>Telegram-доступ до оплати частинами</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_installment_telegram_bot_token">Bot Token</label></th>
                    <td><input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_installment_telegram_bot_token" name="vip_tattoo_plan_installment_telegram_bot_token" value="<?php echo esc_attr($vals['vip_tattoo_plan_installment_telegram_bot_token']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_installment_telegram_bot_username">Юзернейм бота (без @)</label></th>
                    <td><input type="text" id="vip_tattoo_plan_installment_telegram_bot_username" name="vip_tattoo_plan_installment_telegram_bot_username" value="<?php echo esc_attr($vals['vip_tattoo_plan_installment_telegram_bot_username']); ?>" placeholder="vip_tattoo_payment_bot" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_installment_kick_group_ids">Chat ID груп, звідки видаляти при неоплаті (через кому)</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_installment_kick_group_ids" name="vip_tattoo_plan_installment_kick_group_ids" value="<?php echo esc_attr($vals['vip_tattoo_plan_installment_kick_group_ids']); ?>" />
                        <p class="description">Chat_tattoo_school НЕ входить у цей список — з нього користувача не видаляють.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_installment_kick_delay_hours">Скільки годин чекати перед видаленням</label></th>
                    <td><input type="number" id="vip_tattoo_plan_installment_kick_delay_hours" name="vip_tattoo_plan_installment_kick_delay_hours" value="<?php echo esc_attr($vals['vip_tattoo_plan_installment_kick_delay_hours']); ?>" /></td>
                </tr>
                <tr>
                    <th><label>Webhook ендпоінт</label></th>
                    <td><code><?php echo esc_html($installment_telegram_webhook_url); ?></code></td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="vip_tattoo_plan_save_installment_settings" value="1" class="button button-primary">Зберегти налаштування</button>
            </p>
        </form>

        <hr />
        <p>
            <form method="post" style="display:inline-block; margin-right:10px;">
                <?php wp_nonce_field('vip_tattoo_plan_installment_settings', 'vip_tattoo_plan_installment_nonce'); ?>
                <button type="submit" name="vip_tattoo_plan_create_paypal_plan" value="1" class="button">Створити PayPal Billing Plan автоматично</button>
            </form>
            <form method="post" style="display:inline-block; margin-right:10px;">
                <?php wp_nonce_field('vip_tattoo_plan_installment_settings', 'vip_tattoo_plan_installment_nonce'); ?>
                <button type="submit" name="vip_tattoo_plan_test_sheet_row" value="1" class="button">Тестовий запис у Google Sheets</button>
            </form>
            <form method="post" style="display:inline-block;">
                <?php wp_nonce_field('vip_tattoo_plan_installment_settings', 'vip_tattoo_plan_installment_nonce'); ?>
                <button type="submit" name="vip_tattoo_plan_set_installment_telegram_webhook" value="1" class="button">Встановити Telegram webhook (оплати частинами)</button>
            </form>
        </p>
    </div>
    <?php
}

/* ------------------------------------------------------------------ */
/* Stripe: обмеження підписки до 2 циклів                              */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_stripe_installment_price_id() {
    return trim(vip_tattoo_plan_stripe_mode() === 'live'
        ? get_option('vip_tattoo_plan_stripe_live_installment_price_id', '')
        : get_option('vip_tattoo_plan_stripe_test_installment_price_id', ''));
}

function vip_tattoo_plan_stripe_start_installment_checkout($token) {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;

    $price_id = vip_tattoo_plan_stripe_installment_price_id();
    if (!$price_id) {
        return new WP_REST_Response(['error' => 'Оплата частинами через Stripe ще не налаштоване (немає Price ID).'], 500);
    }

    $return_url = add_query_arg([
        'vip_token'  => $token,
        'session_id' => '{CHECKOUT_SESSION_ID}',
    ], rest_url('vip-tattoo-plan/v1/stripe-return'));
    $return_url = str_replace('%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $return_url);

    $body = [
        'mode'                    => 'subscription',
        'success_url'             => $return_url,
        'cancel_url'              => vip_tattoo_plan_page_url(),
        'client_reference_id'     => $token,
        'metadata'                => ['token' => $token, 'plan_type' => 'installment'],
        'subscription_data'       => ['metadata' => ['token' => $token, 'plan_type' => 'installment']],
        'phone_number_collection' => ['enabled' => 'true'],
        'line_items'              => [['price' => $price_id, 'quantity' => 1]],
    ];

    $result = vip_tattoo_plan_stripe_request('POST', '/checkout/sessions', $body);
    if (is_wp_error($result)) {
        return new WP_REST_Response(['error' => $result->get_error_message()], 500);
    }
    if (empty($result['url']) || empty($result['id'])) {
        return new WP_REST_Response(['error' => 'Stripe не повернув посилання на оплату.'], 500);
    }

    $wpdb->update($table, ['provider_order_id' => $result['id']], ['token' => $token]);

    return new WP_REST_Response(['checkout_url' => $result['url']], 200);
}

// Викликається з webhook на checkout.session.completed, коли mode=subscription.
function vip_tattoo_plan_stripe_installment_checkout_completed($session_id, $subscription_id) {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider_order_id = %s", $session_id));
    if (!$order) return;

    $wpdb->update($table, ['stripe_subscription_id' => $subscription_id], ['id' => $order->id]);

    // Перетворюємо звичайну підписку на розклад з рівно 2 циклами --
    // Stripe сам скасує її після 2-го платежу, без ручного лічильника.
    $schedule = vip_tattoo_plan_stripe_request('POST', '/subscription_schedules', ['from_subscription' => $subscription_id]);
    if (is_wp_error($schedule)) {
        error_log('[VIP Tattoo Plan] Не вдалось створити subscription schedule для ' . $subscription_id . ': ' . $schedule->get_error_message());
        return;
    }

    $wpdb->update($table, ['stripe_schedule_id' => $schedule['id']], ['id' => $order->id]);

    $current_phase = $schedule['phases'][0] ?? null;
    if (!$current_phase) return;

    $phase_items = [];
    foreach ($current_phase['items'] as $i => $item) {
        $phase_items[$i]['price'] = $item['price'];
        $phase_items[$i]['quantity'] = $item['quantity'];
    }

    vip_tattoo_plan_stripe_request('POST', '/subscription_schedules/' . $schedule['id'], [
        'end_behavior' => 'cancel',
        'phases'       => [[
            'items'      => $phase_items,
            'iterations' => 2,
            'start_date' => $current_phase['start_date'],
        ]],
    ]);
}

function vip_tattoo_plan_stripe_installment_invoice_paid($invoice) {
    global $wpdb;
    $subscription_id = $invoice['subscription'] ?? '';
    if (!$subscription_id) return;

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE stripe_subscription_id = %s", $subscription_id));
    if (!$order) return;

    $step = (int) $order->installment_step + 1;
    if ($step > 2) return; // ідемпотентність: цей інвойс вже обробляли

    $paid_now_cents = (int) ($invoice['amount_paid'] ?? VIP_TATTOO_PLAN_INSTALLMENT_STEP_CENTS);
    $total_paid = (int) $order->total_paid_cents + $paid_now_cents;
    $now = current_time('mysql');

    $update = [
        'installment_step'  => $step,
        'total_paid_cents'  => $total_paid,
    ];
    if ($step === 1) {
        $update['status'] = 'paid'; // перший платіж вже відкриває доступ
        $update['paid_at'] = $now;
    }
    if ($step >= 2) {
        $update['installment_final_at'] = $now;
    }
    $wpdb->update($table, $update, ['id' => $order->id]);

    $order->installment_step = $step;
    $order->total_paid_cents = $total_paid;
    $order->status = $step === 1 ? 'paid' : $order->status;

    if ($step === 1) {
        vip_tattoo_plan_deliver_access($order);
    }

    $email = $invoice['customer_email'] ?? ($order->email ?? '');
    $phone = $order->phone ?? '';

    vip_tattoo_plan_sheets_append_row([
        $order->id,
        $order->created_at,
        $email,
        $phone,
        'Оплата частинами',
        'Stripe',
        $subscription_id,
        $step . '/2',
        number_format($paid_now_cents / 100, 2, '.', ''),
        number_format($total_paid / 100, 2, '.', ''),
        $step >= 2 ? 'Повністю оплачено (2/2)' : 'Частково оплачено (1/2)',
        $step === 1 ? $now : ($order->paid_at ?? ''),
        $step >= 2 ? $now : '',
        $order->telegram_chat_id ?? '',
        $now,
    ]);

    $subject = $step === 1
        ? 'Оплата 1/2 отримана - доступ до курсу відкрито'
        : 'Оплата 2/2 отримана - курс повністю оплачено';
    $body = $step === 1
        ? "Дякуємо! Перший платіж (137.50€) успішно отримано.\n\nДоступ до курсу вже надіслано в Telegram.\n\nДругий платіж (137.50€) спишеться автоматично через 7 днів."
        : "Дякуємо! Другий платіж (137.50€) успішно отримано - курс повністю оплачено (275€).\n\nПодальших списань не буде.";
    if ($email) {
        vip_tattoo_plan_send_email($email, $subject, $body);
    }
    if ($order->telegram_chat_id) {
        vip_tattoo_plan_installment_telegram_api('sendMessage', [
            'chat_id' => $order->telegram_chat_id,
            'text'    => $body,
        ]);
    }
}

function vip_tattoo_plan_stripe_installment_invoice_failed($invoice) {
    global $wpdb;
    $subscription_id = $invoice['subscription'] ?? '';
    if (!$subscription_id) return;

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE stripe_subscription_id = %s", $subscription_id));
    if (!$order || (int) $order->installment_step >= 2) return; // збій вже після повної оплати -- ігноруємо

    $now = current_time('mysql');
    $email = $invoice['customer_email'] ?? ($order->email ?? '');

    vip_tattoo_plan_sheets_append_row([
        $order->id, $order->created_at, $email, $order->phone ?? '', 'Оплата частинами', 'Stripe',
        $subscription_id, '2/2 — помилка', '', number_format((int) $order->total_paid_cents / 100, 2, '.', ''),
        'Платіж не пройшов', $order->paid_at ?? '', '', $order->telegram_chat_id ?? '', $now,
    ]);

    $warning = "Не вдалося списати другий платіж (137.50€).\n\nОновіть картку протягом 24 годин, щоб зберегти доступ до курсу.";
    if ($email) {
        vip_tattoo_plan_send_email($email, 'Не вдалося списати другий платіж', $warning);
    }
    if ($order->telegram_chat_id) {
        vip_tattoo_plan_installment_telegram_api('sendMessage', [
            'chat_id' => $order->telegram_chat_id,
            'text'    => $warning,
        ]);
    }

    $delay_hours = max(1, (int) get_option('vip_tattoo_plan_installment_kick_delay_hours', 24));
    if (!wp_next_scheduled('vip_tattoo_plan_check_installment_access', [$order->id])) {
        wp_schedule_single_event(time() + $delay_hours * HOUR_IN_SECONDS, 'vip_tattoo_plan_check_installment_access', [$order->id]);
    }
}

function vip_tattoo_plan_stripe_installment_subscription_deleted($subscription) {
    global $wpdb;
    $subscription_id = $subscription['id'] ?? '';
    if (!$subscription_id) return;

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE stripe_subscription_id = %s", $subscription_id));
    if (!$order) return;

    // Якщо повністю оплачено (2/2) -- це нормальне завершення, нічого не робимо.
    if ((int) $order->installment_step >= 2) return;

    // Інакше підписку скасовано через неоплату -- перевіряємо/видаляємо одразу.
    vip_tattoo_plan_check_and_kick_installment_order($order->id);
}

add_action('vip_tattoo_plan_check_installment_access', 'vip_tattoo_plan_check_and_kick_installment_order');

function vip_tattoo_plan_check_and_kick_installment_order($order_id) {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $order_id));
    if (!$order) return;

    if ((int) $order->installment_step >= 2) return; // встигли оплатити -- нічого не робимо
    if (!$order->telegram_chat_id) return; // нема кого видаляти

    vip_tattoo_plan_kick_from_installment_groups($order->telegram_chat_id);

    $wpdb->update($table, ['status' => 'access_revoked'], ['id' => $order->id]);

    vip_tattoo_plan_sheets_append_row([
        $order->id, $order->created_at, $order->email ?? '', $order->phone ?? '', 'Оплата частинами', $order->provider,
        $order->stripe_subscription_id ?? '', '2/2 — не оплачено', '', number_format((int) $order->total_paid_cents / 100, 2, '.', ''),
        'Доступ закрито (2-й платіж не оплачено)', $order->paid_at ?? '', '', $order->telegram_chat_id, current_time('mysql'),
    ]);

    if (!empty($order->email)) {
        vip_tattoo_plan_send_email($order->email, 'Доступ до курсу закрито', "Оскільки другий платіж (137.50€) так і не пройшов протягом 24 годин, доступ до навчальних груп курсу було закрито.\n\nЩоб відновити доступ, зверніться до підтримки.");
    }
}

/* ------------------------------------------------------------------ */
/* PayPal: Billing Plan (137.50 EUR x 2, тиждень)                      */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_paypal_installment_plan_id() {
    return trim(vip_tattoo_plan_paypal_mode() === 'live'
        ? get_option('vip_tattoo_plan_paypal_live_installment_plan_id', '')
        : get_option('vip_tattoo_plan_paypal_sandbox_installment_plan_id', ''));
}

function vip_tattoo_plan_paypal_create_installment_plan() {
    $product = vip_tattoo_plan_paypal_request('POST', '/v1/catalogs/products', [
        'name'        => get_option('vip_tattoo_plan_product_name', 'VIP tattoo school - курс') . ' (оплати частинами)',
        'type'        => 'SERVICE',
        'category'    => 'EDUCATIONAL_SERVICES_AND_LEARNING_COURSES',
    ]);
    if (is_wp_error($product)) return $product;

    $currency = strtoupper(get_option('vip_tattoo_plan_currency', 'EUR'));
    $amount_value = number_format(VIP_TATTOO_PLAN_INSTALLMENT_STEP_CENTS / 100, 2, '.', '');

    $plan = vip_tattoo_plan_paypal_request('POST', '/v1/billing/plans', [
        'product_id'          => $product['id'],
        'name'                => 'Оплата частинами 2 платежі',
        'billing_cycles'      => [[
            'frequency'      => ['interval_unit' => 'WEEK', 'interval_count' => 1],
            'tenure_type'    => 'REGULAR',
            'sequence'       => 1,
            'total_cycles'   => 2,
            'pricing_scheme' => ['fixed_price' => ['value' => $amount_value, 'currency_code' => $currency]],
        ]],
        'payment_preferences' => [
            'auto_bill_outstanding'     => true,
            'payment_failure_threshold' => 1,
        ],
    ]);
    if (is_wp_error($plan)) return $plan;

    $option_key = vip_tattoo_plan_paypal_mode() === 'live'
        ? 'vip_tattoo_plan_paypal_live_installment_plan_id'
        : 'vip_tattoo_plan_paypal_sandbox_installment_plan_id';
    update_option($option_key, $plan['id']);

    return $plan['id'];
}

function vip_tattoo_plan_paypal_start_installment_checkout($token) {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;

    $plan_id = vip_tattoo_plan_paypal_installment_plan_id();
    if (!$plan_id) {
        return new WP_REST_Response(['error' => 'Оплата частинами через PayPal ще не налаштоване (немає Plan ID).'], 500);
    }

    $return_url = add_query_arg('vip_token', $token, rest_url('vip-tattoo-plan/v1/paypal-return'));

    $result = vip_tattoo_plan_paypal_request('POST', '/v1/billing/subscriptions', [
        'plan_id'      => $plan_id,
        'custom_id'    => $token,
        'application_context' => [
            'brand_name'  => get_option('vip_tattoo_plan_product_name', 'VIP tattoo school - курс'),
            'user_action' => 'SUBSCRIBE_NOW',
            'return_url'  => $return_url,
            'cancel_url'  => vip_tattoo_plan_page_url(),
        ],
    ]);
    if (is_wp_error($result)) {
        return new WP_REST_Response(['error' => $result->get_error_message()], 500);
    }

    $approve_url = '';
    foreach ($result['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve') { $approve_url = $link['href']; break; }
    }
    if (!$approve_url || empty($result['id'])) {
        return new WP_REST_Response(['error' => 'PayPal не повернув посилання на оплату.'], 500);
    }

    $wpdb->update($table, ['provider_order_id' => $result['id']], ['token' => $token]);

    return new WP_REST_Response(['checkout_url' => $approve_url], 200);
}

function vip_tattoo_plan_paypal_installment_sale_completed($subscription_id, $resource) {
    global $wpdb;
    if (!$subscription_id) return;

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider_order_id = %s", $subscription_id));
    if (!$order) return;

    $step = (int) $order->installment_step + 1;
    if ($step > 2) return;

    $paid_now_cents = (int) round((float) ($resource['amount']['total'] ?? '137.50') * 100);
    $total_paid = (int) $order->total_paid_cents + $paid_now_cents;
    $now = current_time('mysql');

    $update = ['installment_step' => $step, 'total_paid_cents' => $total_paid];
    if ($step === 1) { $update['status'] = 'paid'; $update['paid_at'] = $now; }
    if ($step >= 2) { $update['installment_final_at'] = $now; }
    $wpdb->update($table, $update, ['id' => $order->id]);

    $order->installment_step = $step;
    $order->status = $step === 1 ? 'paid' : $order->status;

    if ($step === 1) {
        vip_tattoo_plan_deliver_access($order);
    }

    vip_tattoo_plan_sheets_append_row([
        $order->id, $order->created_at, $order->email ?? '', $order->phone ?? '', 'Оплата частинами', 'PayPal',
        $subscription_id, $step . '/2', number_format($paid_now_cents / 100, 2, '.', ''), number_format($total_paid / 100, 2, '.', ''),
        $step >= 2 ? 'Повністю оплачено (2/2)' : 'Частково оплачено (1/2)',
        $step === 1 ? $now : ($order->paid_at ?? ''), $step >= 2 ? $now : '', $order->telegram_chat_id ?? '', $now,
    ]);
}

function vip_tattoo_plan_paypal_installment_payment_failed($subscription_id) {
    global $wpdb;
    if (!$subscription_id) return;

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider_order_id = %s", $subscription_id));
    if (!$order || (int) $order->installment_step >= 2) return;

    $warning = "Не вдалося списати другий платіж (137.50€).\n\nОновіть спосіб оплати в PayPal протягом 24 годин, щоб зберегти доступ до курсу.";
    if (!empty($order->email)) vip_tattoo_plan_send_email($order->email, 'Не вдалося списати другий платіж', $warning);
    if ($order->telegram_chat_id) {
        vip_tattoo_plan_installment_telegram_api('sendMessage', ['chat_id' => $order->telegram_chat_id, 'text' => $warning]);
    }

    $delay_hours = max(1, (int) get_option('vip_tattoo_plan_installment_kick_delay_hours', 24));
    if (!wp_next_scheduled('vip_tattoo_plan_check_installment_access', [$order->id])) {
        wp_schedule_single_event(time() + $delay_hours * HOUR_IN_SECONDS, 'vip_tattoo_plan_check_installment_access', [$order->id]);
    }
}

function vip_tattoo_plan_paypal_installment_subscription_cancelled($subscription_id) {
    global $wpdb;
    if (!$subscription_id) return;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider_order_id = %s", $subscription_id));
    if (!$order || (int) $order->installment_step >= 2) return;
    vip_tattoo_plan_check_and_kick_installment_order($order->id);
}

/* ------------------------------------------------------------------ */
/* Telegram: бот оплати частинами + видалення з груп                    */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_installment_telegram_api($method, $params = []) {
    $token = get_option('vip_tattoo_plan_installment_telegram_bot_token');
    if (!$token) return new WP_Error('no_token', 'Installment bot token не налаштований');
    $response = wp_remote_post("https://api.telegram.org/bot{$token}/{$method}", ['body' => $params, 'timeout' => 15]);
    if (is_wp_error($response)) return $response;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['ok'])) return new WP_Error('telegram_error', $body['description'] ?? 'Unknown Telegram API error');
    return $body['result'];
}

function vip_tattoo_plan_kick_from_installment_groups($telegram_user_id) {
    $group_ids = array_filter(array_map('trim', explode(',', get_option('vip_tattoo_plan_installment_kick_group_ids', ''))));
    foreach ($group_ids as $group_id) {
        vip_tattoo_plan_installment_telegram_api('banChatMember', ['chat_id' => $group_id, 'user_id' => $telegram_user_id]);
        vip_tattoo_plan_installment_telegram_api('unbanChatMember', ['chat_id' => $group_id, 'user_id' => $telegram_user_id, 'only_if_banned' => true]);
    }
}

/* ------------------------------------------------------------------ */
/* Email (Gmail SMTP)                                                  */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_configure_smtp($phpmailer) {
    $username = get_option('vip_tattoo_plan_smtp_username');
    $password = get_option('vip_tattoo_plan_smtp_password');
    if (!$username || !$password) return;

    $phpmailer->isSMTP();
    $phpmailer->Host = get_option('vip_tattoo_plan_smtp_host', 'smtp.gmail.com');
    $phpmailer->Port = (int) get_option('vip_tattoo_plan_smtp_port', 587);
    $phpmailer->SMTPAuth = true;
    $phpmailer->SMTPSecure = 'tls';
    $phpmailer->Username = $username;
    $phpmailer->Password = $password;

    $from_email = get_option('vip_tattoo_plan_smtp_from_email') ?: $username;
    $from_name = get_option('vip_tattoo_plan_smtp_from_name', 'VIP Tattoo School');
    $phpmailer->setFrom($from_email, $from_name);
}

function vip_tattoo_plan_send_email($to, $subject, $body) {
    if (!$to || !is_email($to)) return false;
    add_action('phpmailer_init', 'vip_tattoo_plan_configure_smtp');
    $sent = wp_mail($to, $subject, $body);
    remove_action('phpmailer_init', 'vip_tattoo_plan_configure_smtp');
    return $sent;
}

/* ------------------------------------------------------------------ */
/* Google Sheets (журнал платежів)                                     */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_google_service_account_file() {
    return VIP_TATTOO_PLAN_PLUGIN_DIR . 'config/google-service-account.json';
}

function vip_tattoo_plan_google_service_account_email() {
    $file = vip_tattoo_plan_google_service_account_file();
    if (!file_exists($file)) return '';
    $creds = json_decode(file_get_contents($file), true);
    return $creds['client_email'] ?? '';
}

function vip_tattoo_plan_google_access_token() {
    $cached = get_transient('vip_tattoo_plan_google_token');
    if ($cached) return $cached;

    $file = vip_tattoo_plan_google_service_account_file();
    if (!file_exists($file)) return new WP_Error('no_key_file', 'Google service account ключ не знайдено.');

    $creds = json_decode(file_get_contents($file), true);
    if (empty($creds['private_key']) || empty($creds['client_email'])) {
        return new WP_Error('bad_key_file', 'Google service account ключ пошкоджений.');
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $b64 = function ($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    $segments = [$b64(wp_json_encode($header)), $b64(wp_json_encode($claim))];
    $signing_input = implode('.', $segments);

    $signature = '';
    $ok = openssl_sign($signing_input, $signature, $creds['private_key'], 'sha256WithRSAEncryption');
    if (!$ok) return new WP_Error('sign_failed', 'Не вдалось підписати JWT для Google.');

    $segments[] = $b64($signature);
    $jwt = implode('.', $segments);

    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'body'    => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['access_token'])) {
        return new WP_Error('google_auth_error', $data['error_description'] ?? 'Google auth error');
    }

    set_transient('vip_tattoo_plan_google_token', $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 60));
    return $data['access_token'];
}

function vip_tattoo_plan_sheets_append_row($values) {
    $sheet_id = get_option('vip_tattoo_plan_google_sheet_id');
    if (!$sheet_id) return new WP_Error('no_sheet_id', 'Google Sheet ID не налаштований.');

    $token = vip_tattoo_plan_google_access_token();
    if (is_wp_error($token)) {
        error_log('[VIP Tattoo Plan] Google auth error: ' . $token->get_error_message());
        return $token;
    }

    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/A:O:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS";
    $response = wp_remote_post($url, [
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['values' => [$values]]),
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) {
        error_log('[VIP Tattoo Plan] Sheets append error: ' . $response->get_error_message());
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 400) {
        $msg = wp_remote_retrieve_body($response);
        error_log('[VIP Tattoo Plan] Sheets append HTTP ' . $code . ': ' . $msg);
        return new WP_Error('sheets_http_' . $code, $msg);
    }
    return true;
}

/* ------------------------------------------------------------------ */
/* REST: окремий webhook для бота оплати частинами                      */
/* ------------------------------------------------------------------ */

add_action('rest_api_init', function () {
    register_rest_route('vip-tattoo-plan/v1', '/installment-telegram-webhook', [
        'methods'             => 'POST',
        'callback'            => 'vip_tattoo_plan_rest_installment_telegram_webhook',
        'permission_callback' => '__return_true',
    ]);
});

function vip_tattoo_plan_rest_installment_telegram_webhook(WP_REST_Request $request) {
    global $wpdb;

    $update = json_decode($request->get_body(), true);
    $text = $update['message']['text'] ?? '';
    $chat_id = $update['message']['chat']['id'] ?? null;

    if ($chat_id && preg_match('/^\/start\s+(\S+)/', $text, $m)) {
        $token = $m[1];
        $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token = %s", $token));

        if ($order) {
            $wpdb->update($table, ['telegram_chat_id' => $chat_id], ['id' => $order->id]);
            $order->telegram_chat_id = $chat_id;
            if ($order->status === 'paid') {
                vip_tattoo_plan_deliver_access($order);
            } else {
                vip_tattoo_plan_installment_telegram_api('sendMessage', [
                    'chat_id' => $chat_id,
                    'text'    => 'Дякуємо! Обробляємо твою оплату - доступ надішлемо сюди протягом хвилини.',
                ]);
            }
        } else {
            vip_tattoo_plan_installment_telegram_api('sendMessage', [
                'chat_id' => $chat_id,
                'text'    => 'Привіт! Схоже, посилання застаріле або невірне.',
            ]);
        }
    }

    return new WP_REST_Response(['ok' => true], 200);
}

function vip_tattoo_plan_set_installment_telegram_webhook() {
    $secret = get_option('vip_tattoo_plan_telegram_webhook_secret');
    $url = rest_url('vip-tattoo-plan/v1/installment-telegram-webhook');
    $result = vip_tattoo_plan_installment_telegram_api('setWebhook', ['url' => $url, 'secret_token' => $secret]);
    if (is_wp_error($result)) return $result->get_error_message();
    return true;
}
