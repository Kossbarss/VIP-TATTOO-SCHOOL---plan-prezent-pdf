<?php
/**
 * Stripe + PayPal checkout (test/sandbox and live credentials, both kept
 * saved side by side and switched by a mode radio) with Telegram access
 * delivery -- same architecture as the vip-tattoo-landing plugin's own
 * includes/payments.php, ported here under a vip_tattoo_plan_ prefix and
 * its own DB table/REST namespace so the two plugins never collide even
 * when both are active on the same site.
 *
 * This is the one and only checkout path for the CTA button -- there is
 * no separate "plain link" fallback. js/checkout.js always intercepts the
 * click and runs the flow below.
 *
 * Flow: CTA click -> small email/phone popup -> REST "create-checkout"
 * opens a Stripe Checkout Session or a PayPal Order depending on the
 * active provider, stores a pending order row keyed by a random token ->
 * customer pays on the provider's hosted page -> provider redirects back
 * to our own "*-return" endpoint, which captures the order and forwards
 * the browser on to the Telegram bot's deep-link start URL -> our
 * Telegram webhook receives /start <token>, matches it against the
 * order, and replies with the access message. The provider's own webhook
 * (checkout.session.completed / CHECKOUT.ORDER.APPROVED) is the fallback
 * that captures the order if the browser never makes it back.
 */

if (!defined('ABSPATH')) exit;

define('VIP_TATTOO_PLAN_ORDERS_TABLE', 'vip_tattoo_plan_orders');

/* ------------------------------------------------------------------ */
/* Activation: create the orders table                                */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_payments_activate() {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token VARCHAR(64) NOT NULL,
        provider_order_id VARCHAR(191) DEFAULT NULL,
        provider VARCHAR(20) DEFAULT NULL,
        email VARCHAR(191) DEFAULT NULL,
        phone VARCHAR(64) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        telegram_chat_id BIGINT DEFAULT NULL,
        delivered_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        paid_at DATETIME DEFAULT NULL,
        plan_type VARCHAR(20) NOT NULL DEFAULT 'full',
        installment_step SMALLINT NOT NULL DEFAULT 0,
        total_paid_cents BIGINT NOT NULL DEFAULT 0,
        stripe_subscription_id VARCHAR(191) DEFAULT NULL,
        stripe_schedule_id VARCHAR(191) DEFAULT NULL,
        installment_final_at DATETIME DEFAULT NULL,
        name VARCHAR(191) DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY token (token)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    if (!get_option('vip_tattoo_plan_telegram_webhook_secret')) {
        update_option('vip_tattoo_plan_telegram_webhook_secret', wp_generate_password(32, false));
    }
}
register_activation_hook(VIP_TATTOO_PLAN_PLUGIN_DIR . 'vip-tattoo-plan-viewer.php', 'vip_tattoo_plan_payments_activate');

define('VIP_TATTOO_PLAN_PAYMENTS_DB_VERSION', 3);
add_action('admin_init', function () {
    if ((int) get_option('vip_tattoo_plan_payments_db_version', 0) < VIP_TATTOO_PLAN_PAYMENTS_DB_VERSION) {
        vip_tattoo_plan_payments_activate();
        update_option('vip_tattoo_plan_payments_db_version', VIP_TATTOO_PLAN_PAYMENTS_DB_VERSION);
    }
});

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_payment_fields() {
    return [
        'vip_tattoo_plan_payment_provider'           => 'stripe',

        'vip_tattoo_plan_stripe_mode'                => 'test',
        'vip_tattoo_plan_stripe_test_secret_key'     => '',
        'vip_tattoo_plan_stripe_test_webhook_secret' => '',
        'vip_tattoo_plan_stripe_test_price_id'       => '',
        'vip_tattoo_plan_stripe_live_secret_key'     => '',
        'vip_tattoo_plan_stripe_live_webhook_secret' => '',
        'vip_tattoo_plan_stripe_live_price_id'       => '',
        'vip_tattoo_plan_stripe_product_image'       => 'hero-founder-portrait.jpg',
        'vip_tattoo_plan_stripe_product_description' => 'Онлайн курс від тату-майстра Вікторії Понікарової з 9-річним досвідом. Довічний доступ, сертифікат, підтримка куратора.',
        'vip_tattoo_plan_stripe_submit_message'      => 'Оплата захищена Stripe. Доступ до курсу надійде в Telegram одразу після оплати.',

        'vip_tattoo_plan_paypal_mode'                => 'sandbox',
        'vip_tattoo_plan_paypal_sandbox_client_id'   => '',
        'vip_tattoo_plan_paypal_sandbox_secret'      => '',
        'vip_tattoo_plan_paypal_sandbox_webhook_id'  => '',
        'vip_tattoo_plan_paypal_live_client_id'      => '',
        'vip_tattoo_plan_paypal_live_secret'         => '',
        'vip_tattoo_plan_paypal_live_webhook_id'     => '',

        'vip_tattoo_plan_price_cents'                => '27500',
        'vip_tattoo_plan_currency'                   => 'EUR',
        'vip_tattoo_plan_product_name'               => 'VIP tattoo school - курс',

        'vip_tattoo_plan_receipt_business_name'      => 'Vip tattoo school',
        'vip_tattoo_plan_receipt_business_nip'       => '6492327660',
        'vip_tattoo_plan_receipt_business_regon'     => '524031644',

        'vip_tattoo_plan_telegram_access_bot_token'    => '',
        'vip_tattoo_plan_telegram_access_bot_username' => 'vip_tattoo_school_viktori_bot',
        'vip_tattoo_plan_telegram_access_message'      => "Спасибо за покупку обучения! Ваша оплата успешно прошла 🎉\n\nПереходите и присоединяйтесь к учебной программе, где вы сейчас увидите 15 блоков, наполненных материалами и уроками, по этой ссылке (сделайте запрос, и администратор сразу вас добавит):\n\nhttps://t.me/+cUtIkWv6ljo5NmQy\n\nСсылка для доступа к материалам:\n\nhttps://t.me/+F_eC8wV1jqtiMWQy\n\nНа этом доступе находится общий чат, а также в нем есть навигация по всему курсу, чтобы вам было проще найти необходимый материал и загрузить его, ссылка (доступ):\n\nhttps://t.me/+XUvrwztuyXQ2NGZi\n\nМоя рекомендация вам - сначала просмотрите всё наполнение, то есть «пробегитесь» по всем блокам и всему обучению, чтобы понять, где и что находится и как работает «навигатор», и только после этого, в уверенном настроении, начинайте обучение по урокам, и конечно, не забывайте о общении в чате.\n\nУточнение: если что-то не получается загрузить или любая «кнопка» не работает, сразу пишите в чат поддержки.\n\nЕсли возникнут какие-либо вопросы - я всегда на связи 👌",
    ];
}

add_action('admin_menu', function () {
    add_menu_page(
        'VIP Tattoo План — Загальне',
        'VIP Tattoo План: Оплата',
        'manage_options',
        'vip-tattoo-plan-payments',
        'vip_tattoo_plan_render_general_settings',
        'dashicons-money-alt'
    );
    add_submenu_page('vip-tattoo-plan-payments', 'Загальне', 'Загальне', 'manage_options', 'vip-tattoo-plan-payments', 'vip_tattoo_plan_render_general_settings');
    add_submenu_page('vip-tattoo-plan-payments', 'Stripe (повна оплата)', 'Stripe (повна оплата)', 'manage_options', 'vip-tattoo-plan-stripe-full', 'vip_tattoo_plan_render_stripe_full_settings');
    add_submenu_page('vip-tattoo-plan-payments', 'PayPal (повна оплата)', 'PayPal (повна оплата)', 'manage_options', 'vip-tattoo-plan-paypal-full', 'vip_tattoo_plan_render_paypal_full_settings');
    add_submenu_page('vip-tattoo-plan-payments', 'Telegram (повна оплата)', 'Telegram (повна оплата)', 'manage_options', 'vip-tattoo-plan-telegram-full', 'vip_tattoo_plan_render_telegram_full_settings');
    add_submenu_page('vip-tattoo-plan-payments', 'Тести та інструменти', 'Тести та інструменти', 'manage_options', 'vip-tattoo-plan-tools', 'vip_tattoo_plan_render_tools_page');
}, 20);

/*
 * За замовчуванням WordPress показує підменю пунктів (Загальне, Stripe,
 * PayPal...) як спливаючий список збоку при наведенні курсору -- це
 * стандартна поведінка адмінки для будь-якого плагіна з підменю. Тут ми
 * примусово "приколюємо" підменю цього плагіна розкритим під основним
 * пунктом (як акордеон), щоб не доводилось наводити курсор збоку.
 */
add_action('admin_head', function () {
    ?>
    <style>
        #adminmenu #toplevel_page_vip-tattoo-plan-payments > .wp-submenu {
            position: static !important;
            display: block !important;
            box-shadow: none !important;
            margin: 0 !important;
            padding: 6px 0 !important;
            width: auto !important;
            opacity: 1 !important;
            clip: auto !important;
        }
        #adminmenu #toplevel_page_vip-tattoo-plan-payments .wp-submenu:before {
            content: none !important;
        }
    </style>
    <?php
});

function vip_tattoo_plan_save_settings_subset($keys, $defaults) {
    $textarea_keys = ['vip_tattoo_plan_telegram_access_message', 'vip_tattoo_plan_stripe_product_description', 'vip_tattoo_plan_stripe_submit_message'];
    foreach ($keys as $key) {
        $default = $defaults[$key] ?? '';
        if (in_array($key, $textarea_keys, true)) {
            update_option($key, isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : $default);
        } else {
            update_option($key, isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default);
        }
    }
}

function vip_tattoo_plan_settings_tabs_nav($active) {
    $tabs = [
        'vip-tattoo-plan-payments'    => 'Загальне',
        'vip-tattoo-plan-stripe-full' => 'Stripe (повна)',
        'vip-tattoo-plan-paypal-full' => 'PayPal (повна)',
        'vip-tattoo-plan-telegram-full' => 'Telegram (повна)',
        'vip-tattoo-plan-installments' => 'Розстрочка: Stripe',
        'vip-tattoo-plan-paypal-installment' => 'Розстрочка: PayPal',
        'vip-tattoo-plan-telegram-installment' => 'Розстрочка: Telegram',
        'vip-tattoo-plan-smtp'        => 'Email / SMTP',
        'vip-tattoo-plan-sheets'      => 'Google Sheets',
        'vip-tattoo-plan-tools'       => 'Тести та інструменти',
    ];
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $class = ($slug === $active) ? 'nav-tab nav-tab-active' : 'nav-tab';
        echo '<a href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
    }
    echo '</h2>';
}

function vip_tattoo_plan_render_general_settings() {
    if (!current_user_can('manage_options')) return;
    $defaults = vip_tattoo_plan_payment_fields();
    $keys = ['vip_tattoo_plan_payment_provider', 'vip_tattoo_plan_price_cents', 'vip_tattoo_plan_currency', 'vip_tattoo_plan_product_name', 'vip_tattoo_plan_receipt_business_name', 'vip_tattoo_plan_receipt_business_nip', 'vip_tattoo_plan_receipt_business_regon'];

    if (isset($_POST['vip_tattoo_plan_save_payment_settings']) && wp_verify_nonce($_POST['vip_tattoo_plan_payment_nonce'] ?? '', 'vip_tattoo_plan_payment_settings')) {
        vip_tattoo_plan_save_settings_subset($keys, $defaults);
        echo '<div class="notice notice-success"><p>Збережено.</p></div>';
    }

    $vals = [];
    foreach ($defaults as $key => $default) {
        $vals[$key] = get_option($key, $default);
    }
    ?>
    <div class="wrap">
        <h1>VIP Tattoo School — План курсу: Оплата і Telegram-доступ</h1>
        <?php vip_tattoo_plan_settings_tabs_nav('vip-tattoo-plan-payments'); ?>
        <p class="description">Загальні налаштування, спільні для повної оплати і розстрочки: ціна повної оплати, валюта, назва товару, реквізити для email-квитанції.</p>

        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_payment_settings', 'vip_tattoo_plan_payment_nonce'); ?>

            <h2>Провайдер оплати</h2>
            <table class="form-table">
                <tr>
                    <th><label>Активний зараз</label></th>
                    <td>
                        <label><input type="radio" name="vip_tattoo_plan_payment_provider" value="stripe" <?php checked($vals['vip_tattoo_plan_payment_provider'], 'stripe'); ?> /> Stripe</label><br />
                        <label><input type="radio" name="vip_tattoo_plan_payment_provider" value="paypal" <?php checked($vals['vip_tattoo_plan_payment_provider'], 'paypal'); ?> /> PayPal</label>
                        <p class="description">Кнопка оплати на сторінці завжди веде через цей провайдер (стосується повної оплати 275€). Налаштування іншого провайдера нікуди не зникають — можна перемкнутись назад у будь-який момент.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_price_cents">Ціна (в центах)</label></th>
                    <td><input type="number" id="vip_tattoo_plan_price_cents" name="vip_tattoo_plan_price_cents" value="<?php echo esc_attr($vals['vip_tattoo_plan_price_cents']); ?>" /> <p class="description">27500 = 275.00</p></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_currency">Валюта (код ISO, напр. EUR)</label></th>
                    <td><input type="text" id="vip_tattoo_plan_currency" name="vip_tattoo_plan_currency" value="<?php echo esc_attr($vals['vip_tattoo_plan_currency']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_product_name">Назва товару (показується на сторінці оплати)</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_product_name" name="vip_tattoo_plan_product_name" value="<?php echo esc_attr($vals['vip_tattoo_plan_product_name']); ?>" /></td>
                </tr>
            </table>

            <h2>Реквізити для email-квитанції</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_receipt_business_name">Назва бізнесу</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_receipt_business_name" name="vip_tattoo_plan_receipt_business_name" value="<?php echo esc_attr($vals['vip_tattoo_plan_receipt_business_name']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_receipt_business_nip">NIP</label></th>
                    <td><input type="text" id="vip_tattoo_plan_receipt_business_nip" name="vip_tattoo_plan_receipt_business_nip" value="<?php echo esc_attr($vals['vip_tattoo_plan_receipt_business_nip']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_receipt_business_regon">REGON</label></th>
                    <td><input type="text" id="vip_tattoo_plan_receipt_business_regon" name="vip_tattoo_plan_receipt_business_regon" value="<?php echo esc_attr($vals['vip_tattoo_plan_receipt_business_regon']); ?>" /></td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="vip_tattoo_plan_save_payment_settings" value="1" class="button button-primary">Зберегти налаштування</button>
            </p>
        </form>
    </div>
    <?php
}

function vip_tattoo_plan_render_stripe_full_settings() {
    if (!current_user_can('manage_options')) return;
    $defaults = vip_tattoo_plan_payment_fields();
    $keys = ['vip_tattoo_plan_stripe_mode', 'vip_tattoo_plan_stripe_test_secret_key', 'vip_tattoo_plan_stripe_test_webhook_secret', 'vip_tattoo_plan_stripe_test_price_id', 'vip_tattoo_plan_stripe_live_secret_key', 'vip_tattoo_plan_stripe_live_webhook_secret', 'vip_tattoo_plan_stripe_live_price_id', 'vip_tattoo_plan_stripe_product_image', 'vip_tattoo_plan_stripe_product_description', 'vip_tattoo_plan_stripe_submit_message'];

    if (isset($_POST['vip_tattoo_plan_save_payment_settings']) && wp_verify_nonce($_POST['vip_tattoo_plan_payment_nonce'] ?? '', 'vip_tattoo_plan_payment_settings')) {
        vip_tattoo_plan_save_settings_subset($keys, $defaults);
        echo '<div class="notice notice-success"><p>Збережено.</p></div>';
    }

    $vals = [];
    foreach ($defaults as $key => $default) {
        $vals[$key] = get_option($key, $default);
    }
    $stripe_webhook_url = rest_url('vip-tattoo-plan/v1/stripe-webhook');
    ?>
    <div class="wrap">
        <h1>Stripe — повна оплата (275€)</h1>
        <?php vip_tattoo_plan_settings_tabs_nav('vip-tattoo-plan-stripe-full'); ?>

        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_payment_settings', 'vip_tattoo_plan_payment_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label>Режим</label></th>
                    <td>
                        <label><input type="radio" name="vip_tattoo_plan_stripe_mode" value="test" <?php checked($vals['vip_tattoo_plan_stripe_mode'], 'test'); ?> /> Test (тестові платежі, картка 4242 4242 4242 4242)</label><br />
                        <label><input type="radio" name="vip_tattoo_plan_stripe_mode" value="live" <?php checked($vals['vip_tattoo_plan_stripe_mode'], 'live'); ?> /> Live (реальні платежі)</label>
                        <p class="description">Перемикає, які з двох наборів полів (Test/Live) фактично використовуються — обидва завжди залишаються збереженими.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_test_secret_key">Test Secret Key</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_stripe_test_secret_key" name="vip_tattoo_plan_stripe_test_secret_key" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_test_secret_key']); ?>" />
                        <p class="description">З <a href="https://dashboard.stripe.com/test/apikeys" target="_blank" rel="noopener">dashboard.stripe.com/test/apikeys</a> (увімкни "Test mode" зверху). Починається з <code>sk_test_</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_test_webhook_secret">Test Webhook Signing Secret</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_stripe_test_webhook_secret" name="vip_tattoo_plan_stripe_test_webhook_secret" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_test_webhook_secret']); ?>" />
                        <p class="description">У Test mode: Developers → Webhooks → "Add destination" на URL нижче, подія <code>checkout.session.completed</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_test_price_id">Test Price ID (необов'язково)</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_stripe_test_price_id" name="vip_tattoo_plan_stripe_test_price_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_test_price_id']); ?>" placeholder="price_..." /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_live_secret_key">Live Secret Key</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_stripe_live_secret_key" name="vip_tattoo_plan_stripe_live_secret_key" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_live_secret_key']); ?>" />
                        <p class="description">З <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">dashboard.stripe.com/apikeys</a>. Починається з <code>sk_live_</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_live_webhook_secret">Live Webhook Signing Secret</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_stripe_live_webhook_secret" name="vip_tattoo_plan_stripe_live_webhook_secret" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_live_webhook_secret']); ?>" />
                        <p class="description">
                            У dashboard.stripe.com (поза Test mode) → Developers → Webhooks → "Add destination". URL ендпоінта:<br />
                            <code><?php echo esc_html($stripe_webhook_url); ?></code><br />
                            Подія для підписки: <code>checkout.session.completed</code>. Після створення скопіюй "Signing secret" (<code>whsec_...</code>) сюди.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_live_price_id">Live Price ID з каталогу Stripe (необов'язково)</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_stripe_live_price_id" name="vip_tattoo_plan_stripe_live_price_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_live_price_id']); ?>" placeholder="price_..." />
                        <p class="description">
                            Якщо заповнено — оплата йде саме на цю ціну з каталогу dashboard.stripe.com → Product catalog, і сторінка оплати покаже фото/опис/список функцій, які ти там налаштував(-ла) вручну. Поля «Фото товару»/«Опис товару» нижче тоді ігноруються.<br />
                            Якщо порожньо — плагін сам створює одноразовий товар на льоту з тих полів, ігноруючи каталог.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_product_image">Фото товару (ім'я файлу з assets/) — тільки якщо Price ID порожній</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vip_tattoo_plan_stripe_product_image" name="vip_tattoo_plan_stripe_product_image" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_product_image']); ?>" />
                        <p class="description">Показується поруч із назвою товару на сторінці оплати Stripe. Ім'я файлу з папки <code>assets/</code> плагіна.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_product_description">Опис товару (під назвою) — тільки якщо Price ID порожній</label></th>
                    <td><textarea id="vip_tattoo_plan_stripe_product_description" name="vip_tattoo_plan_stripe_product_description" rows="3" class="large-text"><?php echo esc_textarea($vals['vip_tattoo_plan_stripe_product_description']); ?></textarea></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_submit_message">Текст біля кнопки оплати</label></th>
                    <td><textarea id="vip_tattoo_plan_stripe_submit_message" name="vip_tattoo_plan_stripe_submit_message" rows="2" class="large-text"><?php echo esc_textarea($vals['vip_tattoo_plan_stripe_submit_message']); ?></textarea></td>
                </tr>
                <tr>
                    <th><label>Лого, іконка, колір бренду</label></th>
                    <td><p class="description">Це налаштовується не тут, а напряму в dashboard.stripe.com → Settings → Branding — вони застосовуються автоматично до всіх сторінок оплати цього акаунту.</p></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="vip_tattoo_plan_save_payment_settings" value="1" class="button button-primary">Зберегти налаштування</button>
            </p>
        </form>
    </div>
    <?php
}

function vip_tattoo_plan_render_paypal_full_settings() {
    if (!current_user_can('manage_options')) return;
    $defaults = vip_tattoo_plan_payment_fields();
    $keys = ['vip_tattoo_plan_paypal_mode', 'vip_tattoo_plan_paypal_sandbox_client_id', 'vip_tattoo_plan_paypal_sandbox_secret', 'vip_tattoo_plan_paypal_sandbox_webhook_id', 'vip_tattoo_plan_paypal_live_client_id', 'vip_tattoo_plan_paypal_live_secret', 'vip_tattoo_plan_paypal_live_webhook_id'];

    if (isset($_POST['vip_tattoo_plan_save_payment_settings']) && wp_verify_nonce($_POST['vip_tattoo_plan_payment_nonce'] ?? '', 'vip_tattoo_plan_payment_settings')) {
        vip_tattoo_plan_save_settings_subset($keys, $defaults);
        echo '<div class="notice notice-success"><p>Збережено.</p></div>';
    }

    $vals = [];
    foreach ($defaults as $key => $default) {
        $vals[$key] = get_option($key, $default);
    }
    $paypal_webhook_url = rest_url('vip-tattoo-plan/v1/paypal-webhook');
    ?>
    <div class="wrap">
        <h1>PayPal — повна оплата (275€)</h1>
        <?php vip_tattoo_plan_settings_tabs_nav('vip-tattoo-plan-paypal-full'); ?>

        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_payment_settings', 'vip_tattoo_plan_payment_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label>Режим</label></th>
                    <td>
                        <label><input type="radio" name="vip_tattoo_plan_paypal_mode" value="sandbox" <?php checked($vals['vip_tattoo_plan_paypal_mode'], 'sandbox'); ?> /> Sandbox (тестові платежі)</label><br />
                        <label><input type="radio" name="vip_tattoo_plan_paypal_mode" value="live" <?php checked($vals['vip_tattoo_plan_paypal_mode'], 'live'); ?> /> Live (реальні платежі)</label>
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
                            У developer.paypal.com (Sandbox) → твій застосунок → "Sandbox Webhooks" → "Add Webhook". URL ендпоінта:<br />
                            <code><?php echo esc_html($paypal_webhook_url); ?></code><br />
                            Події для підписки: <code>Checkout order approved</code> і <code>Payment capture completed</code>. Після створення скопіюй "Webhook ID" сюди.
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

function vip_tattoo_plan_render_telegram_full_settings() {
    if (!current_user_can('manage_options')) return;
    $defaults = vip_tattoo_plan_payment_fields();
    $keys = ['vip_tattoo_plan_telegram_access_bot_token', 'vip_tattoo_plan_telegram_access_bot_username', 'vip_tattoo_plan_telegram_access_message'];

    if (isset($_POST['vip_tattoo_plan_save_payment_settings']) && wp_verify_nonce($_POST['vip_tattoo_plan_payment_nonce'] ?? '', 'vip_tattoo_plan_payment_settings')) {
        vip_tattoo_plan_save_settings_subset($keys, $defaults);
        echo '<div class="notice notice-success"><p>Збережено.</p></div>';
    }

    if (isset($_POST['vip_tattoo_plan_set_telegram_webhook']) && wp_verify_nonce($_POST['vip_tattoo_plan_payment_nonce'] ?? '', 'vip_tattoo_plan_payment_settings')) {
        $result = vip_tattoo_plan_set_telegram_webhook();
        if ($result === true) {
            echo '<div class="notice notice-success"><p>Telegram webhook встановлено успішно.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Помилка встановлення webhook: ' . esc_html($result) . '</p></div>';
        }
    }

    $vals = [];
    foreach ($defaults as $key => $default) {
        $vals[$key] = get_option($key, $default);
    }
    $telegram_webhook_url = rest_url('vip-tattoo-plan/v1/telegram-webhook');
    ?>
    <div class="wrap">
        <h1>Telegram — доступ після повної оплати (275€)</h1>
        <?php vip_tattoo_plan_settings_tabs_nav('vip-tattoo-plan-telegram-full'); ?>

        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_payment_settings', 'vip_tattoo_plan_payment_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_telegram_access_bot_token">Bot Token (від @BotFather)</label></th>
                    <td>
                        <input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_telegram_access_bot_token" name="vip_tattoo_plan_telegram_access_bot_token" value="<?php echo esc_attr($vals['vip_tattoo_plan_telegram_access_bot_token']); ?>" />
                        <p class="description">Окремий бот від бота розстрочки і від бота адмін-сповіщень — саме цей пише клієнту, який оплатив повну суму 275€ одразу.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_telegram_access_bot_username">Юзернейм бота (без @)</label></th>
                    <td><input type="text" id="vip_tattoo_plan_telegram_access_bot_username" name="vip_tattoo_plan_telegram_access_bot_username" value="<?php echo esc_attr($vals['vip_tattoo_plan_telegram_access_bot_username']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_telegram_access_message">Текст повідомлення з доступом</label></th>
                    <td><textarea id="vip_tattoo_plan_telegram_access_message" name="vip_tattoo_plan_telegram_access_message" rows="6" class="large-text"><?php echo esc_textarea($vals['vip_tattoo_plan_telegram_access_message']); ?></textarea></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="vip_tattoo_plan_save_payment_settings" value="1" class="button button-primary">Зберегти налаштування</button>
            </p>
        </form>

        <hr />
        <h2>Telegram webhook</h2>
        <p>Після того як зберіг Bot Token вище — натисни цю кнопку один раз, щоб підключити бота до сайту:</p>
        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_payment_settings', 'vip_tattoo_plan_payment_nonce'); ?>
            <button type="submit" name="vip_tattoo_plan_set_telegram_webhook" value="1" class="button">Встановити Telegram webhook</button>
        </form>
        <p class="description">Ендпоінт: <code><?php echo esc_html($telegram_webhook_url); ?></code></p>
    </div>
    <?php
}

function vip_tattoo_plan_render_tools_page() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['vip_tattoo_plan_clear_orders']) && wp_verify_nonce($_POST['vip_tattoo_plan_payment_nonce'] ?? '', 'vip_tattoo_plan_payment_settings')) {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}" . VIP_TATTOO_PLAN_ORDERS_TABLE);
        echo '<div class="notice notice-success"><p>Усі замовлення видалено.</p></div>';
    }

    if (isset($_POST['vip_tattoo_plan_send_test_receipt']) && wp_verify_nonce($_POST['vip_tattoo_plan_payment_nonce'] ?? '', 'vip_tattoo_plan_payment_settings')) {
        $test_email = sanitize_email(wp_unslash($_POST['vip_tattoo_plan_test_receipt_email'] ?? ''));
        $test_type  = sanitize_text_field(wp_unslash($_POST['vip_tattoo_plan_send_test_receipt']));
        if (!$test_email || !is_email($test_email)) {
            echo '<div class="notice notice-error"><p>Вкажи коректний email для тестової розсилки.</p></div>';
        } else {
            $sent = vip_tattoo_plan_send_test_receipt($test_email, $test_type);
            if ($sent) {
                echo '<div class="notice notice-success"><p>Тестовий лист (' . esc_html($test_type) . ') надіслано на ' . esc_html($test_email) . '.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Не вдалося надіслати тестовий лист — перевір SMTP-налаштування.</p></div>';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Тести та інструменти</h1>
        <?php vip_tattoo_plan_settings_tabs_nav('vip-tattoo-plan-tools'); ?>

        <h2>Тестова розсилка квитанцій (рубильник)</h2>
        <p class="description">Надішли собі будь-яку з 3 квитанцій із тестовими даними — без реального платежу і без потреби чекати на тестову оплату в Stripe/PayPal.</p>
        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_payment_settings', 'vip_tattoo_plan_payment_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_test_receipt_email">Email для тестових листів</label></th>
                    <td><input type="email" class="regular-text" id="vip_tattoo_plan_test_receipt_email" name="vip_tattoo_plan_test_receipt_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" /></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="vip_tattoo_plan_send_test_receipt" value="success" class="button button-primary">✅ Тест: Успішна оплата</button>
                <button type="submit" name="vip_tattoo_plan_send_test_receipt" value="final" class="button button-primary">🎓 Тест: Курс оплачено повністю</button>
                <button type="submit" name="vip_tattoo_plan_send_test_receipt" value="failed" class="button button-primary">❌ Тест: Платіж не пройшов</button>
            </p>
        </form>

        <hr />
        <h2>Останні замовлення (повна оплата + розстрочка)</h2>
        <?php vip_tattoo_plan_render_recent_orders(); ?>
        <form method="post" onsubmit="return confirm('Видалити ВСІ замовлення з таблиці? Це не можна скасувати.');">
            <?php wp_nonce_field('vip_tattoo_plan_payment_settings', 'vip_tattoo_plan_payment_nonce'); ?>
            <p class="submit"><button type="submit" name="vip_tattoo_plan_clear_orders" value="1" class="button">Видалити всі замовлення (наприклад, тестові)</button></p>
        </form>
    </div>
    <?php
}

function vip_tattoo_plan_render_recent_orders() {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 25");
    if (!$rows) {
        echo '<p>Поки що немає замовлень.</p>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>Дата і час</th><th>Оплата</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        printf(
            '<tr><td>%s</td><td>%s</td></tr>',
            esc_html($row->created_at),
            $row->status === 'paid' ? '✓ оплачено ' . esc_html($row->paid_at) : '— очікує оплати'
        );
    }
    echo '</tbody></table>';
}

/* ------------------------------------------------------------------ */
/* PayPal helpers                                                      */
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
        $error = new WP_Error('paypal_http_' . $code, 'PayPal HTTP ' . $code . ': ' . $message);
        $error->add_data($data);
        return $error;
    }

    return $data;
}

/* ------------------------------------------------------------------ */
/* Stripe helpers                                                      */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_payment_provider() {
    $provider = get_option('vip_tattoo_plan_payment_provider', 'stripe');
    return in_array($provider, ['stripe', 'paypal'], true) ? $provider : 'stripe';
}

function vip_tattoo_plan_stripe_mode() {
    return get_option('vip_tattoo_plan_stripe_mode', 'test') === 'live' ? 'live' : 'test';
}

function vip_tattoo_plan_stripe_secret_key() {
    return vip_tattoo_plan_stripe_mode() === 'live'
        ? get_option('vip_tattoo_plan_stripe_live_secret_key', '')
        : get_option('vip_tattoo_plan_stripe_test_secret_key', '');
}

function vip_tattoo_plan_stripe_webhook_secret() {
    return vip_tattoo_plan_stripe_mode() === 'live'
        ? get_option('vip_tattoo_plan_stripe_live_webhook_secret', '')
        : get_option('vip_tattoo_plan_stripe_test_webhook_secret', '');
}

/**
 * Two separate Stripe dashboard "destinations" deliver to the same
 * /stripe-webhook URL (the original one-time-payment endpoint, and a
 * second one added for the installment events) -- each has its own
 * signing secret, so verification must accept either, not just the
 * one originally configured.
 */
function vip_tattoo_plan_stripe_webhook_secrets() {
    $secrets = [vip_tattoo_plan_stripe_webhook_secret()];
    $secrets[] = vip_tattoo_plan_stripe_mode() === 'live'
        ? get_option('vip_tattoo_plan_stripe_live_webhook_secret_installment', '')
        : get_option('vip_tattoo_plan_stripe_test_webhook_secret_installment', '');
    return array_filter($secrets);
}

function vip_tattoo_plan_stripe_price_id() {
    return trim(vip_tattoo_plan_stripe_mode() === 'live'
        ? get_option('vip_tattoo_plan_stripe_live_price_id', '')
        : get_option('vip_tattoo_plan_stripe_test_price_id', ''));
}

function vip_tattoo_plan_stripe_request($method, $path, $body = null) {
    $secret = vip_tattoo_plan_stripe_secret_key();
    if (!$secret) {
        return new WP_Error('no_credentials', 'Stripe Secret Key не налаштований.');
    }

    $args = [
        'method'  => $method,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret,
        ],
        'timeout' => 20,
    ];
    if ($body !== null) {
        $args['body'] = $body;
    }

    $response = wp_remote_request('https://api.stripe.com/v1' . $path, $args);
    if (is_wp_error($response)) return $response;

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($code >= 400) {
        $message = is_array($data) ? ($data['error']['message'] ?? wp_remote_retrieve_body($response)) : wp_remote_retrieve_body($response);
        return new WP_Error('stripe_http_' . $code, 'Stripe HTTP ' . $code . ': ' . $message);
    }

    return $data;
}

function vip_tattoo_plan_stripe_capture_and_mark_paid($session_id) {
    global $wpdb;
    if (!$session_id) return;

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider_order_id = %s", $session_id));
    if (!$order) return;

    // Installment orders are captured and delivered exclusively through
    // their own Stripe subscription/invoice webhooks (see installments.php)
    // -- this shared one-time-payment path must never touch them, or it
    // ends up paging the full-payment Telegram bot for a customer who only
    // has a chat with the installment bot.
    if (($order->plan_type ?? 'full') === 'installment') return;

    if ($order->status === 'paid') {
        vip_tattoo_plan_deliver_access($order);
        return;
    }

    $session = vip_tattoo_plan_stripe_request('GET', '/checkout/sessions/' . $session_id . '?expand[]=payment_intent.latest_charge');
    if (is_wp_error($session)) {
        error_log('[VIP Tattoo Plan] Stripe session lookup failed for ' . $session_id . ': ' . $session->get_error_message());
        return;
    }

    if (($session['payment_status'] ?? '') !== 'paid') {
        error_log('[VIP Tattoo Plan] Stripe session ' . $session_id . ' payment_status: ' . ($session['payment_status'] ?? 'unknown'));
        return;
    }

    $paid_at = current_time('mysql');
    // Email/phone come from Stripe's own hosted checkout page, never from a
    // form on this site -- captured here purely for support/delivery
    // purposes, never surfaced on the "Останні замовлення" admin table.
    $wpdb->update($table, [
        'status'  => 'paid',
        'paid_at' => $paid_at,
        'email'   => $session['customer_details']['email'] ?? null,
        'phone'   => $session['customer_details']['phone'] ?? null,
        'name'    => $session['customer_details']['name'] ?? null,
    ], ['id' => $order->id]);
    $order->status = 'paid';
    $order->paid_at = $paid_at;
    $order->name = $session['customer_details']['name'] ?? '';

    // deliver_access() often runs later, from the Telegram /start webhook,
    // against a freshly re-fetched $order row -- so this extra receipt
    // detail (unavailable on the orders table) has to survive that gap via
    // a transient keyed by order id rather than as a property on $order.
    $charge = $session['payment_intent']['latest_charge'] ?? null;
    if (is_array($charge)) {
        set_transient('vip_tattoo_plan_receipt_extra_' . $order->id, [
            'card_last4' => $charge['payment_method_details']['card']['last4'] ?? '',
            'card_brand' => $charge['payment_method_details']['card']['brand'] ?? '',
            'auth_code'  => $charge['id'] ?? '',
            'buyer_name' => $session['customer_details']['name'] ?? '',
        ], HOUR_IN_SECONDS);
    }

    vip_tattoo_plan_deliver_access($order);
}

function vip_tattoo_plan_verify_stripe_webhook(WP_REST_Request $request) {
    $webhook_secrets = vip_tattoo_plan_stripe_webhook_secrets();
    if (!$webhook_secrets) return true;

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

    foreach ($webhook_secrets as $webhook_secret) {
        $expected = hash_hmac('sha256', $timestamp . '.' . $request->get_body(), $webhook_secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* Telegram access delivery                                            */
/* ------------------------------------------------------------------ */

function vip_tattoo_plan_telegram_access_api($method, $params = []) {
    $token = get_option('vip_tattoo_plan_telegram_access_bot_token');
    if (!$token) return new WP_Error('no_token', 'Telegram bot token not configured');
    $response = wp_remote_post("https://api.telegram.org/bot{$token}/{$method}", [
        'body'    => $params,
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return $response;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['ok'])) {
        return new WP_Error('telegram_error', $body['description'] ?? 'Unknown Telegram API error');
    }
    return $body['result'];
}

function vip_tattoo_plan_set_telegram_webhook() {
    $secret = get_option('vip_tattoo_plan_telegram_webhook_secret');
    $url = rest_url('vip-tattoo-plan/v1/telegram-webhook');
    $result = vip_tattoo_plan_telegram_access_api('setWebhook', [
        'url'          => $url,
        'secret_token' => $secret,
    ]);
    if (is_wp_error($result)) return $result->get_error_message();
    return true;
}

function vip_tattoo_plan_deliver_access($order) {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;

    if ($order->status !== 'paid' || !$order->telegram_chat_id || $order->delivered_at) {
        return;
    }

    $message = get_option('vip_tattoo_plan_telegram_access_message');
    $result = vip_tattoo_plan_telegram_access_api('sendMessage', [
        'chat_id' => $order->telegram_chat_id,
        'text'    => $message,
    ]);

    if (!is_wp_error($result)) {
        $wpdb->update($table, ['delivered_at' => current_time('mysql')], ['id' => $order->id]);
    }

    if (!empty($order->email)) {
        $extra = get_transient('vip_tattoo_plan_receipt_extra_' . $order->id) ?: [];
        $receipt_args = [
            'order_id'     => $order->id,
            'amount'       => number_format((int) get_option('vip_tattoo_plan_price_cents', 27500) / 100, 2, '.', ''),
            'currency'     => strtoupper(get_option('vip_tattoo_plan_currency', 'EUR')),
            'date'         => date_i18n('d.m.Y', strtotime($order->paid_at ?: current_time('mysql'))),
            'method'       => $order->provider === 'paypal' ? 'PayPal' : 'Card (Stripe)',
            'buyer_email'  => $order->email,
            'buyer_phone'  => $order->phone ?? '',
            'buyer_name'   => $order->name ?? ($extra['buyer_name'] ?? ''),
            'card_last4'   => $extra['card_last4'] ?? '',
            'card_brand'   => $extra['card_brand'] ?? '',
            'auth_code'    => $extra['auth_code'] ?? '',
        ];
        vip_tattoo_plan_send_receipt_email($order->email, 'Оплата прошла успешно - VIP tattoo school', $receipt_args);

        // Обов'язково дублюємо квитанцію в Telegram-бот -- якщо клієнт не
        // побачить лист на пошті, повідомлення все одно дійде.
        vip_tattoo_plan_telegram_access_api('sendMessage', [
            'chat_id'    => $order->telegram_chat_id,
            'text'       => vip_tattoo_plan_telegram_receipt_text('success', $receipt_args),
            'parse_mode' => 'HTML',
        ]);
    }
}

function vip_tattoo_plan_paypal_capture_and_mark_paid($paypal_order_id) {
    global $wpdb;
    if (!$paypal_order_id) return;

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE provider_order_id = %s", $paypal_order_id));
    if (!$order) return;

    // Same reasoning as the Stripe version above: installment orders (PayPal
    // subscriptions, not one-time "orders") are captured and delivered
    // exclusively through their own PayPal webhooks in installments.php.
    if (($order->plan_type ?? 'full') === 'installment') return;

    if ($order->status === 'paid') {
        vip_tattoo_plan_deliver_access($order);
        return;
    }

    $result = vip_tattoo_plan_paypal_request('POST', '/v2/checkout/orders/' . $paypal_order_id . '/capture', new stdClass());

    $already_captured = false;
    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        $issue = $data['details'][0]['issue'] ?? '';
        if ($issue === 'ORDER_ALREADY_CAPTURED') {
            $already_captured = true;
        } else {
            error_log('[VIP Tattoo Plan] PayPal capture failed for order ' . $paypal_order_id . ': ' . $result->get_error_message());
            return;
        }
    }

    $status = $already_captured ? 'COMPLETED' : ($result['status'] ?? '');
    if ($status !== 'COMPLETED') {
        error_log('[VIP Tattoo Plan] PayPal capture for order ' . $paypal_order_id . ' returned status: ' . $status);
        return;
    }

    $paid_at = current_time('mysql');
    // Email comes from PayPal's own payer record, never from a form on
    // this site -- captured purely for support/delivery, never shown on
    // the "Останні замовлення" admin table.
    $wpdb->update($table, [
        'status'  => 'paid',
        'paid_at' => $paid_at,
        'email'   => $already_captured ? null : ($result['payer']['email_address'] ?? null),
    ], ['id' => $order->id]);
    $order->status = 'paid';
    $order->paid_at = $paid_at;

    vip_tattoo_plan_deliver_access($order);
}

/* ------------------------------------------------------------------ */
/* REST routes                                                         */
/* ------------------------------------------------------------------ */

add_action('rest_api_init', function () {
    register_rest_route('vip-tattoo-plan/v1', '/create-checkout', [
        'methods'             => 'POST',
        'callback'            => 'vip_tattoo_plan_rest_create_checkout',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('vip-tattoo-plan/v1', '/paypal-return', [
        'methods'             => 'GET',
        'callback'            => 'vip_tattoo_plan_rest_paypal_return',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('vip-tattoo-plan/v1', '/paypal-webhook', [
        'methods'             => 'POST',
        'callback'            => 'vip_tattoo_plan_rest_paypal_webhook',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('vip-tattoo-plan/v1', '/stripe-return', [
        'methods'             => 'GET',
        'callback'            => 'vip_tattoo_plan_rest_stripe_return',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('vip-tattoo-plan/v1', '/stripe-webhook', [
        'methods'             => 'POST',
        'callback'            => 'vip_tattoo_plan_rest_stripe_webhook',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('vip-tattoo-plan/v1', '/telegram-webhook', [
        'methods'             => 'POST',
        'callback'            => 'vip_tattoo_plan_rest_telegram_webhook',
        'permission_callback' => '__return_true',
    ]);
});

// No form, no popup: this site never asks the visitor for anything.
// Email/phone (if the provider happens to collect one on its own hosted
// page) only ever reach us later, via the webhook payload, and are
// stored purely so the Telegram delivery/support flow has something to
// go on -- never shown on the admin "Останні замовлення" table.
function vip_tattoo_plan_rest_create_checkout(WP_REST_Request $request) {
    global $wpdb;

    $provider = vip_tattoo_plan_payment_provider();
    $plan_type = $request->get_param('plan_type') === 'installment' ? 'installment' : 'full';
    $token = wp_generate_password(40, false, false);
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $wpdb->insert($table, [
        'token'      => $token,
        'provider'   => $provider,
        'status'     => 'pending',
        'plan_type'  => $plan_type,
        'created_at' => current_time('mysql'),
    ]);

    if ($plan_type === 'installment') {
        if ($provider === 'stripe') {
            return vip_tattoo_plan_stripe_start_installment_checkout($token);
        }
        return vip_tattoo_plan_paypal_start_installment_checkout($token);
    }

    $price_cents = (int) get_option('vip_tattoo_plan_price_cents', 27500);
    $currency = strtoupper(get_option('vip_tattoo_plan_currency', 'EUR'));
    $product_name = get_option('vip_tattoo_plan_product_name', 'VIP tattoo school - курс');
    $amount_value = number_format($price_cents / 100, 2, '.', '');

    if ($provider === 'stripe') {
        return vip_tattoo_plan_stripe_start_checkout($token, $price_cents, $currency, $product_name);
    }

    $return_url = add_query_arg('vip_token', $token, rest_url('vip-tattoo-plan/v1/paypal-return'));
    $cancel_url = vip_tattoo_plan_page_url();

    $order_body = [
        'intent'         => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $token,
            'custom_id'    => $token,
            'description'  => $product_name,
            'amount'       => [
                'currency_code' => $currency,
                'value'         => $amount_value,
            ],
        ]],
        'application_context' => [
            'brand_name'  => $product_name,
            'user_action' => 'PAY_NOW',
            'return_url'  => $return_url,
            'cancel_url'  => $cancel_url,
        ],
    ];

    $result = vip_tattoo_plan_paypal_request('POST', '/v2/checkout/orders', $order_body);
    if (is_wp_error($result)) {
        return new WP_REST_Response(['error' => $result->get_error_message()], 500);
    }

    $approve_url = '';
    foreach ($result['links'] ?? [] as $link) {
        if (($link['rel'] ?? '') === 'approve') {
            $approve_url = $link['href'];
            break;
        }
    }
    if (!$approve_url || empty($result['id'])) {
        return new WP_REST_Response(['error' => 'PayPal не повернув посилання на оплату.'], 500);
    }

    $wpdb->update($table, ['provider_order_id' => $result['id']], ['token' => $token]);

    return new WP_REST_Response(['checkout_url' => $approve_url], 200);
}

function vip_tattoo_plan_rest_paypal_return(WP_REST_Request $request) {
    $vip_token = sanitize_text_field($request->get_param('vip_token'));
    $paypal_order_id = sanitize_text_field($request->get_param('token'));

    if ($paypal_order_id) {
        vip_tattoo_plan_paypal_capture_and_mark_paid($paypal_order_id);
    }

    $bot_username = vip_tattoo_plan_telegram_bot_username_for_token($vip_token);
    $redirect_to = $vip_token
        ? ('https://t.me/' . $bot_username . '?start=' . $vip_token)
        : vip_tattoo_plan_page_url();

    wp_redirect($redirect_to);
    exit;
}

function vip_tattoo_plan_stripe_start_checkout($token, $price_cents, $currency, $product_name) {
    global $wpdb;
    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;

    $return_url = add_query_arg([
        'vip_token'  => $token,
        'session_id' => '{CHECKOUT_SESSION_ID}',
    ], rest_url('vip-tattoo-plan/v1/stripe-return'));
    $return_url = str_replace('%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $return_url);
    $cancel_url = vip_tattoo_plan_page_url();

    $price_id = vip_tattoo_plan_stripe_price_id();

    if ($price_id) {
        $line_item = [
            'price'    => $price_id,
            'quantity' => 1,
        ];
    } else {
        $product_data = ['name' => $product_name];

        $description = get_option('vip_tattoo_plan_stripe_product_description', '');
        if ($description) $product_data['description'] = $description;

        $image_filename = get_option('vip_tattoo_plan_stripe_product_image', '');
        if ($image_filename) $product_data['images'] = [VIP_TATTOO_PLAN_PLUGIN_URL . 'assets/' . $image_filename];

        $line_item = [
            'quantity'   => 1,
            'price_data' => [
                'currency'     => strtolower($currency),
                'unit_amount'  => $price_cents,
                'product_data' => $product_data,
            ],
        ];
    }

    $body = [
        'mode'                     => 'payment',
        'success_url'              => $return_url,
        'cancel_url'               => $cancel_url,
        'client_reference_id'      => $token,
        'metadata'                 => ['token' => $token],
        'line_items'               => [$line_item],
        'phone_number_collection'  => ['enabled' => 'true'],
        'billing_address_collection' => 'required',
    ];

    $submit_message = get_option('vip_tattoo_plan_stripe_submit_message', '');
    if ($submit_message) {
        $body['custom_text'] = ['submit' => ['message' => $submit_message]];
    }

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

function vip_tattoo_plan_rest_stripe_return(WP_REST_Request $request) {
    $vip_token = sanitize_text_field($request->get_param('vip_token'));
    $session_id = sanitize_text_field($request->get_param('session_id'));

    if ($session_id) {
        vip_tattoo_plan_stripe_capture_and_mark_paid($session_id);
    }

    $bot_username = vip_tattoo_plan_telegram_bot_username_for_token($vip_token);
    $redirect_to = $vip_token
        ? ('https://t.me/' . $bot_username . '?start=' . $vip_token)
        : vip_tattoo_plan_page_url();

    wp_redirect($redirect_to);
    exit;
}

/**
 * The "full" and "installment" plans each have their own Telegram bot
 * (and their own webhook, which is what actually records telegram_chat_id
 * on the order) -- this picks the right one for a given checkout token so
 * the customer lands in the bot that will recognize their /start payload,
 * instead of always the full-payment bot regardless of which plan they
 * bought.
 */
function vip_tattoo_plan_telegram_bot_username_for_token($token) {
    global $wpdb;
    $full_bot_username = get_option('vip_tattoo_plan_telegram_access_bot_username', 'vip_tattoo_school_viktori_bot');
    if (!$token) return $full_bot_username;

    $table = $wpdb->prefix . VIP_TATTOO_PLAN_ORDERS_TABLE;
    $plan_type = $wpdb->get_var($wpdb->prepare("SELECT plan_type FROM {$table} WHERE token = %s", $token));

    if ($plan_type === 'installment') {
        return get_option('vip_tattoo_plan_installment_telegram_bot_username', 'vip_tattoo_payment_bot');
    }
    return $full_bot_username;
}

function vip_tattoo_plan_verify_paypal_webhook(WP_REST_Request $request) {
    $webhook_id = vip_tattoo_plan_paypal_webhook_id();
    if (!$webhook_id) return true;

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

function vip_tattoo_plan_rest_paypal_webhook(WP_REST_Request $request) {
    if (!vip_tattoo_plan_verify_paypal_webhook($request)) {
        return new WP_REST_Response(['error' => 'Invalid signature'], 400);
    }

    $event = json_decode($request->get_body(), true);
    $event_type = $event['event_type'] ?? '';
    $resource = $event['resource'] ?? [];

    if ($event_type === 'CHECKOUT.ORDER.APPROVED') {
        vip_tattoo_plan_paypal_capture_and_mark_paid($resource['id'] ?? '');
    } elseif ($event_type === 'PAYMENT.CAPTURE.COMPLETED') {
        $order_id = $resource['supplementary_data']['related_ids']['order_id'] ?? '';
        vip_tattoo_plan_paypal_capture_and_mark_paid($order_id);
    } elseif ($event_type === 'PAYMENT.SALE.COMPLETED') {
        $subscription_id = $resource['billing_agreement_id'] ?? '';
        vip_tattoo_plan_paypal_installment_sale_completed($subscription_id, $resource);
    } elseif (in_array($event_type, ['PAYMENT.SALE.DENIED', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED'], true)) {
        $subscription_id = $resource['billing_agreement_id'] ?? ($resource['id'] ?? '');
        vip_tattoo_plan_paypal_installment_payment_failed($subscription_id);
    } elseif ($event_type === 'BILLING.SUBSCRIPTION.CANCELLED') {
        vip_tattoo_plan_paypal_installment_subscription_cancelled($resource['id'] ?? '');
    }

    return new WP_REST_Response(['received' => true], 200);
}

function vip_tattoo_plan_rest_stripe_webhook(WP_REST_Request $request) {
    if (!vip_tattoo_plan_verify_stripe_webhook($request)) {
        return new WP_REST_Response(['error' => 'Invalid signature'], 400);
    }

    $event = json_decode($request->get_body(), true);
    $event_type = $event['type'] ?? '';
    $debug = 'no handler matched event_type=' . $event_type;

    if ($event_type === 'checkout.session.completed') {
        $session = $event['data']['object'] ?? [];
        $session_id = $session['id'] ?? '';
        if (($session['mode'] ?? '') === 'subscription' && !empty($session['subscription'])) {
            $debug = vip_tattoo_plan_stripe_installment_checkout_completed($session_id, $session['subscription'], $session['customer_details']['name'] ?? '', $session['customer_details']['phone'] ?? '');
        } else {
            vip_tattoo_plan_stripe_capture_and_mark_paid($session_id);
            $debug = 'full payment capture ran for session=' . $session_id;
        }
    } elseif ($event_type === 'invoice.payment_succeeded') {
        $invoice = $event['data']['object'] ?? [];
        $debug = vip_tattoo_plan_stripe_installment_invoice_paid($invoice);
    } elseif ($event_type === 'invoice.payment_failed') {
        $invoice = $event['data']['object'] ?? [];
        vip_tattoo_plan_stripe_installment_invoice_failed($invoice);
    } elseif ($event_type === 'customer.subscription.deleted') {
        $subscription = $event['data']['object'] ?? [];
        vip_tattoo_plan_stripe_installment_subscription_deleted($subscription);
    }

    // The `debug` field is a temporary diagnostic aid (visible in Stripe's
    // dashboard under Event deliveries -> Response body) to see exactly
    // which branch ran and why, without needing server log access -- safe
    // to remove once the installment webhook chain is confirmed working.
    return new WP_REST_Response(['received' => true, 'debug' => $debug], 200);
}

function vip_tattoo_plan_rest_telegram_webhook(WP_REST_Request $request) {
    global $wpdb;
    $expected_secret = get_option('vip_tattoo_plan_telegram_webhook_secret');
    $got_secret = $request->get_header('x-telegram-bot-api-secret-token');
    if ($expected_secret && $got_secret !== $expected_secret) {
        return new WP_REST_Response(['error' => 'Invalid secret'], 401);
    }

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
                vip_tattoo_plan_telegram_access_api('sendMessage', [
                    'chat_id' => $chat_id,
                    'text'    => 'Дякуємо! Обробляємо твою оплату - доступ надішлемо сюди протягом хвилини.',
                ]);
            }
        } else {
            vip_tattoo_plan_telegram_access_api('sendMessage', [
                'chat_id' => $chat_id,
                'text'    => 'Привіт! Схоже, посилання застаріле або невірне. Спробуй оформити замовлення на сайті ще раз.',
            ]);
        }
    }

    return new WP_REST_Response(['ok' => true], 200);
}

/* ------------------------------------------------------------------ */
/* Branded HTML email receipt (full payment + installments)            */
/* ------------------------------------------------------------------ */

/**
 * A friendly branded payment confirmation, not a fiscal document -- Stripe
 * itself already offers to email an official receipt (Settings -> Business
 * -> Customer emails -> "Successful payments"); this is a second, separate
 * email in the site's own dark/gold visual language, with a plain-language
 * summary of what was paid and (for installments) what happens next.
 */
function vip_tattoo_plan_render_receipt_email_html($args) {
    $defaults = [
        'order_id'          => '',
        'amount'            => '0.00',
        'currency'          => 'EUR',
        'product_name'      => get_option('vip_tattoo_plan_product_name', 'VIP tattoo school - курс'),
        'date'              => date_i18n('d.m.Y, H:i'),
        'method'            => 'Card',
        'card_last4'        => '',
        'card_brand'        => '',
        'plan_label'        => '',
        'next_payment_note' => '',
        'buyer_email'       => '',
        'buyer_phone'       => '',
        'buyer_name'        => '',
        'auth_code'         => '',
    ];
    $a = array_merge($defaults, $args);

    $business_name  = get_option('vip_tattoo_plan_receipt_business_name', 'Vip tattoo school');
    $business_nip   = get_option('vip_tattoo_plan_receipt_business_nip', '');
    $business_regon = get_option('vip_tattoo_plan_receipt_business_regon', '');
    $site_link      = 'https://website.vip-tattoo-school.com/';

    $top_rows = [];
    $top_rows[] = ['Сайт', esc_html($site_link)];
    if ($business_nip)   $top_rows[] = ['ЄДРПОУ', esc_html($business_nip)];
    if ($business_regon) $top_rows[] = ['IBAN', esc_html($business_regon)];
    $top_rows[] = ['Опис', esc_html($a['product_name']) . ($a['plan_label'] ? ' (' . esc_html($a['plan_label']) . ')' : '')];

    $payment_rows = [];
    if ($a['card_last4']) {
        $card_label = $a['card_brand'] ? esc_html($a['card_brand']) . ' •• ' : '•••• ';
        $payment_rows[] = ['Номер картки', $card_label . esc_html($a['card_last4'])];
    } else {
        $payment_rows[] = ['Способ оплаты', esc_html($a['method'])];
    }
    $payment_rows[] = ['Дата', esc_html($a['date'])];
    $payment_rows[] = ['Id платежу', esc_html($a['order_id'])];
    if ($a['auth_code']) {
        $payment_rows[] = ['Код авторизації', esc_html($a['auth_code'])];
    }

    $payer_rows = [];
    if ($a['buyer_name']) {
        $name_parts = preg_split('/\s+/', trim($a['buyer_name']), 2);
        $first_name = $name_parts[0] ?? '';
        $last_name  = $name_parts[1] ?? '';
        if ($last_name)  $payer_rows[] = ['Прізвище', esc_html($last_name)];
        if ($first_name) $payer_rows[] = ["Ім'я", esc_html($first_name)];
    }
    if ($a['buyer_phone']) $payer_rows[] = ['Телефон', esc_html($a['buyer_phone'])];
    if ($a['buyer_email']) $payer_rows[] = ['Email', esc_html($a['buyer_email'])];

    $render_rows = function ($rows) {
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td style="padding:12px 0;color:#b5b5b5;font-size:27px;vertical-align:top;">' . $row[0] . ':</td>'
                . '<td style="padding:12px 0 12px 12px;color:#ffffff;font-size:27px;text-align:right;">' . $row[1] . '</td>'
                . '</tr>';
        }
        return $html;
    };

    $next_payment_html = '';
    if ($a['next_payment_note']) {
        $next_payment_html = '<div style="margin-top:22px;background:rgba(46,160,35,0.1);border:1px solid rgba(46,160,35,0.35);border-radius:10px;padding:20px;color:#8fe07f;font-size:23px;line-height:1.6;">'
            . esc_html($a['next_payment_note'])
            . '</div>';
    }

    ob_start();
    ?>
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#000000;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#000000;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">
  <tr><td align="center">
    <table role="presentation" width="804" cellpadding="0" cellspacing="0" style="width:804px;max-width:804px;">
      <tr><td style="height:6px;line-height:6px;font-size:0;background:linear-gradient(90deg,#1e8c1a 0%,#4ee23a 50%,#1e8c1a 100%);border-radius:14px 14px 0 0;">&nbsp;</td></tr>
      <tr><td style="background:#0a0a0a;border-radius:0 0 14px 14px;padding:40px 44px 36px;min-height:1274px;">

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
          <img src="<?php echo esc_url(VIP_TATTOO_PLAN_PLUGIN_URL . 'assets/images/receipt-logo.jpg'); ?>" alt="<?php echo esc_attr($business_name); ?>" width="250" style="width:250px;max-width:250px;height:auto;border-radius:50%;display:block;margin:0 auto 20px;" />
        </td></tr></table>

        <div style="text-align:center;color:#3ecb2f;font-size:36px;font-weight:800;">✅ УСПЕШНАЯ ОПЛАТА!</div>
        <div style="text-align:center;color:#a8a8a8;font-size:19px;margin-top:10px;word-break:break-all;">№ <?php echo esc_html($a['order_id']); ?></div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:26px;">
          <tr><td style="color:#b5b5b5;font-size:27px;">Сумма:</td>
              <td style="text-align:right;"><span style="color:#4a9eff;font-size:47px;font-weight:800;"><?php echo esc_html($a['amount']); ?></span> <span style="color:#4a9eff;font-size:27px;font-weight:700;"><?php echo esc_html($a['currency']); ?></span></td></tr>
          <?php echo $render_rows($top_rows); ?>
        </table>

        <div style="color:#ffffff;font-size:24px;font-weight:800;letter-spacing:0.04em;margin-top:28px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.1);">ДАННЫЕ ПЛАТЕЖА</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
          <?php echo $render_rows($payment_rows); ?>
        </table>

        <?php if ($payer_rows) : ?>
        <div style="color:#ffffff;font-size:24px;font-weight:800;letter-spacing:0.04em;margin-top:28px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.1);">ИНФОРМАЦИЯ О ПЛАТЕЛЬЩИКЕ</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
          <?php echo $render_rows($payer_rows); ?>
        </table>
        <?php endif; ?>

        <?php echo $next_payment_html; ?>

        <div style="margin-top:26px;padding-top:18px;border-top:1px solid rgba(255,255,255,0.1);color:#8a8a8a;font-size:18px;line-height:1.7;text-align:center;">
          Это письмо подтверждает оплату, обработанную <?php echo esc_html($business_name); ?>.
        </div>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
    <?php
    return ob_get_clean();
}

function vip_tattoo_plan_render_failed_payment_email_html($args) {
    $defaults = [
        'order_id'      => '',
        'amount'        => '0.00',
        'currency'      => 'EUR',
        'product_name'  => get_option('vip_tattoo_plan_product_name', 'VIP tattoo school - курс'),
        'date'          => date_i18n('d.m.Y, H:i'),
        'method'        => 'Card',
        'card_last4'    => '',
        'card_brand'    => '',
        'plan_label'    => '',
        'buyer_email'   => '',
        'buyer_phone'   => '',
        'buyer_name'    => '',
        'retry_url'     => '',
        'retry_deadline'=> '',
    ];
    $a = array_merge($defaults, $args);

    $business_name = get_option('vip_tattoo_plan_receipt_business_name', 'Vip tattoo school');
    $site_link     = 'https://website.vip-tattoo-school.com/';

    $top_rows = [];
    $top_rows[] = ['Сайт', esc_html($site_link)];
    $top_rows[] = ['Описание', esc_html($a['product_name']) . ($a['plan_label'] ? ' (' . esc_html($a['plan_label']) . ')' : '')];

    $payment_rows = [];
    if ($a['card_last4']) {
        $card_label = $a['card_brand'] ? esc_html($a['card_brand']) . ' •• ' : '•••• ';
        $payment_rows[] = ['Номер карты', $card_label . esc_html($a['card_last4'])];
    } else {
        $payment_rows[] = ['Способ оплаты', esc_html($a['method'])];
    }
    $payment_rows[] = ['Дата', esc_html($a['date'])];
    $payment_rows[] = ['Id платежа', esc_html($a['order_id'])];

    $payer_rows = [];
    if ($a['buyer_name']) {
        $name_parts = preg_split('/\s+/', trim($a['buyer_name']), 2);
        $first_name = $name_parts[0] ?? '';
        $last_name  = $name_parts[1] ?? '';
        if ($last_name)  $payer_rows[] = ['Фамилия', esc_html($last_name)];
        if ($first_name) $payer_rows[] = ['Имя', esc_html($first_name)];
    }
    if ($a['buyer_phone']) $payer_rows[] = ['Телефон', esc_html($a['buyer_phone'])];
    if ($a['buyer_email']) $payer_rows[] = ['Email', esc_html($a['buyer_email'])];

    $render_rows = function ($rows) {
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td style="padding:12px 0;color:#b5b5b5;font-size:27px;vertical-align:top;">' . $row[0] . ':</td>'
                . '<td style="padding:12px 0 12px 12px;color:#ffffff;font-size:27px;text-align:right;">' . $row[1] . '</td>'
                . '</tr>';
        }
        return $html;
    };

    $retry_html = '';
    if ($a['retry_url']) {
        $deadline_html = $a['retry_deadline']
            ? 'Вы можете повторить оплату. Ссылка будет действовать до ' . esc_html($a['retry_deadline'])
            : 'Вы можете повторить оплату по ссылке ниже.';
        $retry_html = '<div style="margin-top:28px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:28px 24px;text-align:center;">'
            . '<div style="color:#d6d6d6;font-size:22px;line-height:1.7;margin-bottom:22px;">' . $deadline_html . '</div>'
            . '<a href="' . esc_url($a['retry_url']) . '" style="display:inline-block;background:#2f6fed;color:#ffffff;text-decoration:none;font-size:25px;font-weight:700;padding:18px 40px;border-radius:8px;">Повторить оплату</a>'
            . '</div>';
    }

    ob_start();
    ?>
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#000000;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#000000;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">
  <tr><td align="center">
    <table role="presentation" width="804" cellpadding="0" cellspacing="0" style="width:804px;max-width:804px;">
      <tr><td style="height:6px;line-height:6px;font-size:0;background:linear-gradient(90deg,#8c1a1a 0%,#e23a3a 50%,#8c1a1a 100%);border-radius:14px 14px 0 0;">&nbsp;</td></tr>
      <tr><td style="background:#0a0a0a;border-radius:0 0 14px 14px;padding:40px 44px 36px;min-height:1274px;">

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
          <img src="<?php echo esc_url(VIP_TATTOO_PLAN_PLUGIN_URL . 'assets/images/receipt-logo.jpg'); ?>" alt="<?php echo esc_attr($business_name); ?>" width="250" style="width:250px;max-width:250px;height:auto;border-radius:50%;display:block;margin:0 auto 20px;" />
        </td></tr></table>

        <div style="text-align:center;color:#e2483f;font-size:36px;font-weight:800;">❌ ПЛАТЁЖ НЕ ПРОШЁЛ!</div>
        <div style="text-align:center;color:#a8a8a8;font-size:19px;margin-top:12px;word-break:break-all;">№ <?php echo esc_html($a['order_id']); ?></div>

        <div style="color:#e2e2e2;font-size:22px;line-height:1.9;margin-top:28px;">
          Не удалось провести оплату с вашей карты.<br>Пожалуйста, проверьте реквизиты карты и интернет-лимит, затем попробуйте снова — либо свяжитесь с банком или используйте другую карту.
        </div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:34px;">
          <tr><td style="color:#b5b5b5;font-size:27px;">Сумма:</td>
              <td style="text-align:right;"><span style="color:#4a9eff;font-size:47px;font-weight:800;"><?php echo esc_html($a['amount']); ?></span> <span style="color:#4a9eff;font-size:27px;font-weight:700;"><?php echo esc_html($a['currency']); ?></span></td></tr>
          <?php echo $render_rows($top_rows); ?>
        </table>

        <?php echo $retry_html; ?>

        <div style="color:#ffffff;font-size:24px;font-weight:800;letter-spacing:0.04em;margin-top:34px;padding-top:24px;border-top:1px solid rgba(255,255,255,0.1);">ДАННЫЕ ПЛАТЕЖА</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
          <?php echo $render_rows($payment_rows); ?>
        </table>

        <?php if ($payer_rows) : ?>
        <div style="color:#ffffff;font-size:24px;font-weight:800;letter-spacing:0.04em;margin-top:28px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.1);">ИНФОРМАЦИЯ О ПЛАТЕЛЬЩИКЕ</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
          <?php echo $render_rows($payer_rows); ?>
        </table>
        <?php endif; ?>

        <div style="margin-top:26px;padding-top:18px;border-top:1px solid rgba(255,255,255,0.1);color:#8a8a8a;font-size:18px;line-height:1.7;text-align:center;">
          Это письмо о статусе платежа, обработанного <?php echo esc_html($business_name); ?>.
        </div>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
    <?php
    return ob_get_clean();
}

function vip_tattoo_plan_render_final_payment_email_html($args) {
    $defaults = [
        'order_id'      => '',
        'amount'        => '0.00',
        'currency'      => 'EUR',
        'total_amount'  => '',
        'product_name'  => get_option('vip_tattoo_plan_product_name', 'VIP tattoo school - курс'),
        'date'          => date_i18n('d.m.Y, H:i'),
        'method'        => 'Card',
        'card_last4'    => '',
        'card_brand'    => '',
        'plan_label'    => '',
        'buyer_email'   => '',
        'buyer_phone'   => '',
        'buyer_name'    => '',
        'auth_code'     => '',
    ];
    $a = array_merge($defaults, $args);

    $business_name = get_option('vip_tattoo_plan_receipt_business_name', 'Vip tattoo school');
    $site_link     = 'https://website.vip-tattoo-school.com/';

    $top_rows = [];
    $top_rows[] = ['Сайт', esc_html($site_link)];
    $top_rows[] = ['Описание', esc_html($a['product_name']) . ($a['plan_label'] ? ' (' . esc_html($a['plan_label']) . ')' : '')];

    $payment_rows = [];
    if ($a['card_last4']) {
        $card_label = $a['card_brand'] ? esc_html($a['card_brand']) . ' •• ' : '•••• ';
        $payment_rows[] = ['Номер карты', $card_label . esc_html($a['card_last4'])];
    } else {
        $payment_rows[] = ['Способ оплаты', esc_html($a['method'])];
    }
    $payment_rows[] = ['Дата', esc_html($a['date'])];
    $payment_rows[] = ['Id платежа', esc_html($a['order_id'])];
    if ($a['auth_code']) {
        $payment_rows[] = ['Код авторизации', esc_html($a['auth_code'])];
    }

    $payer_rows = [];
    if ($a['buyer_name']) {
        $name_parts = preg_split('/\s+/', trim($a['buyer_name']), 2);
        $first_name = $name_parts[0] ?? '';
        $last_name  = $name_parts[1] ?? '';
        if ($last_name)  $payer_rows[] = ['Фамилия', esc_html($last_name)];
        if ($first_name) $payer_rows[] = ['Имя', esc_html($first_name)];
    }
    if ($a['buyer_phone']) $payer_rows[] = ['Телефон', esc_html($a['buyer_phone'])];
    if ($a['buyer_email']) $payer_rows[] = ['Email', esc_html($a['buyer_email'])];

    $render_rows = function ($rows) {
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td style="padding:12px 0;color:#b5b5b5;font-size:27px;vertical-align:top;">' . $row[0] . ':</td>'
                . '<td style="padding:12px 0 12px 12px;color:#ffffff;font-size:27px;text-align:right;">' . $row[1] . '</td>'
                . '</tr>';
        }
        return $html;
    };

    $congrats_html = '<div style="margin-top:22px;background:rgba(226,201,58,0.1);border:1px solid rgba(226,201,58,0.35);border-radius:10px;padding:20px;color:#e8d878;font-size:23px;line-height:1.6;">'
        . 'Поздравляем! Курс оплачен в полном объёме' . ($a['total_amount'] ? ' (' . esc_html($a['total_amount']) . ' ' . esc_html($a['currency']) . ')' : '') . '. Дальнейших списаний не будет.'
        . '</div>';

    ob_start();
    ?>
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#000000;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#000000;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">
  <tr><td align="center">
    <table role="presentation" width="804" cellpadding="0" cellspacing="0" style="width:804px;max-width:804px;">
      <tr><td style="height:6px;line-height:6px;font-size:0;background:linear-gradient(90deg,#8c6d1a 0%,#e2c93a 50%,#8c6d1a 100%);border-radius:14px 14px 0 0;">&nbsp;</td></tr>
      <tr><td style="background:#0a0a0a;border-radius:0 0 14px 14px;padding:40px 44px 36px;min-height:1274px;">

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
          <img src="<?php echo esc_url(VIP_TATTOO_PLAN_PLUGIN_URL . 'assets/images/receipt-logo.jpg'); ?>" alt="<?php echo esc_attr($business_name); ?>" width="250" style="width:250px;max-width:250px;height:auto;border-radius:50%;display:block;margin:0 auto 20px;" />
        </td></tr></table>

        <div style="text-align:center;color:#e2c93a;font-size:36px;font-weight:800;">🎓 КУРС ПОЛНОСТЬЮ ОПЛАЧЕН!</div>
        <div style="text-align:center;color:#a8a8a8;font-size:19px;margin-top:10px;word-break:break-all;">№ <?php echo esc_html($a['order_id']); ?></div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:26px;">
          <tr><td style="color:#b5b5b5;font-size:27px;">Сумма:</td>
              <td style="text-align:right;"><span style="color:#4a9eff;font-size:47px;font-weight:800;"><?php echo esc_html($a['amount']); ?></span> <span style="color:#4a9eff;font-size:27px;font-weight:700;"><?php echo esc_html($a['currency']); ?></span></td></tr>
          <?php echo $render_rows($top_rows); ?>
        </table>

        <?php echo $congrats_html; ?>

        <div style="color:#ffffff;font-size:24px;font-weight:800;letter-spacing:0.04em;margin-top:28px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.1);">ДАННЫЕ ПЛАТЕЖА</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
          <?php echo $render_rows($payment_rows); ?>
        </table>

        <?php if ($payer_rows) : ?>
        <div style="color:#ffffff;font-size:24px;font-weight:800;letter-spacing:0.04em;margin-top:28px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.1);">ИНФОРМАЦИЯ О ПЛАТЕЛЬЩИКЕ</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;">
          <?php echo $render_rows($payer_rows); ?>
        </table>
        <?php endif; ?>

        <div style="margin-top:26px;padding-top:18px;border-top:1px solid rgba(255,255,255,0.1);color:#8a8a8a;font-size:18px;line-height:1.7;text-align:center;">
          Это письмо подтверждает оплату, обработанную <?php echo esc_html($business_name); ?>.
        </div>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
    <?php
    return ob_get_clean();
}

function vip_tattoo_plan_mail_content_type_html() {
    return 'text/html';
}

function vip_tattoo_plan_send_receipt_email($to, $subject, $args) {
    if (!$to || !is_email($to)) return false;
    $html = vip_tattoo_plan_render_receipt_email_html($args);
    add_action('phpmailer_init', 'vip_tattoo_plan_configure_smtp');
    add_filter('wp_mail_content_type', 'vip_tattoo_plan_mail_content_type_html');
    $sent = wp_mail($to, $subject, $html);
    remove_action('phpmailer_init', 'vip_tattoo_plan_configure_smtp');
    remove_filter('wp_mail_content_type', 'vip_tattoo_plan_mail_content_type_html');
    return $sent;
}

function vip_tattoo_plan_send_failed_payment_email($to, $subject, $args) {
    if (!$to || !is_email($to)) return false;
    $html = vip_tattoo_plan_render_failed_payment_email_html($args);
    add_action('phpmailer_init', 'vip_tattoo_plan_configure_smtp');
    add_filter('wp_mail_content_type', 'vip_tattoo_plan_mail_content_type_html');
    $sent = wp_mail($to, $subject, $html);
    remove_action('phpmailer_init', 'vip_tattoo_plan_configure_smtp');
    remove_filter('wp_mail_content_type', 'vip_tattoo_plan_mail_content_type_html');
    return $sent;
}

function vip_tattoo_plan_send_final_payment_email($to, $subject, $args) {
    if (!$to || !is_email($to)) return false;
    $html = vip_tattoo_plan_render_final_payment_email_html($args);
    add_action('phpmailer_init', 'vip_tattoo_plan_configure_smtp');
    add_filter('wp_mail_content_type', 'vip_tattoo_plan_mail_content_type_html');
    $sent = wp_mail($to, $subject, $html);
    remove_action('phpmailer_init', 'vip_tattoo_plan_configure_smtp');
    remove_filter('wp_mail_content_type', 'vip_tattoo_plan_mail_content_type_html');
    return $sent;
}

function vip_tattoo_plan_send_test_receipt($to, $type) {
    $dummy = [
        'order_id'      => 'TEST-' . wp_generate_password(8, false),
        'amount'        => '137.50',
        'currency'      => 'EUR',
        'total_amount'  => '275.00',
        'date'          => date_i18n('d.m.Y, H:i'),
        'method'        => 'Card (Stripe)',
        'card_last4'    => '4242',
        'card_brand'    => 'Visa',
        'plan_label'    => 'Оплата частями - часть 1 из 2',
        'next_payment_note' => 'Второй платёж 137.50€ спишется автоматически ' . date_i18n('d.m.Y', strtotime('+7 days')) . '.',
        'buyer_email'   => $to,
        'buyer_phone'   => '+380501234567',
        'buyer_name'    => 'Иван Петренко',
        'auth_code'     => 'ch_test_' . wp_generate_password(10, false),
        'retry_url'     => 'https://checkout.stripe.com/pay/cs_test_example',
        'retry_deadline'=> date_i18n('H:i d.m.Y', strtotime('+24 hours')),
    ];

    switch ($type) {
        case 'final':
            return vip_tattoo_plan_send_final_payment_email($to, '[ТЕСТ] Оплата 2/2 получена - курс полностью оплачен', $dummy);
        case 'failed':
            return vip_tattoo_plan_send_failed_payment_email($to, '[ТЕСТ] Не удалось списать второй платёж', $dummy);
        case 'success':
        default:
            return vip_tattoo_plan_send_receipt_email($to, '[ТЕСТ] Оплата прошла успешно', $dummy);
    }
}

/*
 * Той самий вміст квитанції, що й у листі, продубльований у Telegram —
 * якщо клієнт не побачить лист на пошті, повідомлення обов'язково
 * прийде і в бот, яким він активував доступ до курсу.
 */
function vip_tattoo_plan_telegram_receipt_text($type, $args) {
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

    $headline = [
        'success' => '✅ <b>УСПЕШНАЯ ОПЛАТА!</b>',
        'final'   => '🎓 <b>КУРС ПОЛНОСТЬЮ ОПЛАЧЕН!</b>',
        'failed'  => '❌ <b>ПЛАТЁЖ НЕ ПРОШЁЛ!</b>',
        'closed'  => '⛔ <b>ДОСТУП К КУРСУ ЗАКРЫТ</b>',
    ][$type] ?? '<b>Уведомление об оплате</b>';

    $lines = [$headline];
    if (!empty($args['order_id'])) $lines[] = '№ ' . $e($args['order_id']);
    $lines[] = '';

    if ($type === 'closed') {
        $lines[] = $e($args['message'] ?? '');
    } else {
        if (isset($args['amount'])) {
            $lines[] = 'Сумма: <b>' . $e($args['amount']) . ' ' . $e($args['currency'] ?? 'EUR') . '</b>';
        }
        $lines[] = 'Сайт: https://website.vip-tattoo-school.com/';
        if (!empty($args['product_name']) || !empty($args['plan_label'])) {
            $lines[] = 'Описание: ' . $e($args['product_name'] ?? '') . (!empty($args['plan_label']) ? ' (' . $e($args['plan_label']) . ')' : '');
        }

        if ($type === 'failed') {
            $lines[] = '';
            $lines[] = 'Не удалось провести оплату с вашей карты. Проверьте реквизиты и интернет-лимит, попробуйте снова.';
            if (!empty($args['retry_url'])) {
                $lines[] = '';
                $lines[] = '🔁 Повторить оплату: ' . $e($args['retry_url']);
                if (!empty($args['retry_deadline'])) {
                    $lines[] = 'Ссылка действует до ' . $e($args['retry_deadline']);
                }
            }
        }

        $lines[] = '';
        $lines[] = '<b>ДАННЫЕ ПЛАТЕЖА</b>';
        if (!empty($args['card_last4'])) {
            $lines[] = 'Карта: ' . ($args['card_brand'] ? $e($args['card_brand']) . ' •• ' : '•••• ') . $e($args['card_last4']);
        } elseif (!empty($args['method'])) {
            $lines[] = 'Способ оплаты: ' . $e($args['method']);
        }
        if (!empty($args['date'])) $lines[] = 'Дата: ' . $e($args['date']);
        if (!empty($args['order_id'])) $lines[] = 'Id платежа: ' . $e($args['order_id']);
        if (!empty($args['auth_code'])) $lines[] = 'Код авторизации: ' . $e($args['auth_code']);

        if (!empty($args['buyer_name']) || !empty($args['buyer_phone']) || !empty($args['buyer_email'])) {
            $lines[] = '';
            $lines[] = '<b>ИНФОРМАЦИЯ О ПЛАТЕЛЬЩИКЕ</b>';
            if (!empty($args['buyer_name'])) {
                $name_parts = preg_split('/\s+/', trim($args['buyer_name']), 2);
                if (!empty($name_parts[1])) $lines[] = 'Фамилия: ' . $e($name_parts[1]);
                if (!empty($name_parts[0])) $lines[] = 'Имя: ' . $e($name_parts[0]);
            }
            if (!empty($args['buyer_phone'])) $lines[] = 'Телефон: ' . $e($args['buyer_phone']);
            if (!empty($args['buyer_email'])) $lines[] = 'Email: ' . $e($args['buyer_email']);
        }

        if ($type === 'success' && !empty($args['next_payment_note'])) {
            $lines[] = '';
            $lines[] = 'ℹ️ ' . $e($args['next_payment_note']);
        }
        if ($type === 'final') {
            $lines[] = '';
            $lines[] = '🎉 Поздравляем! Курс оплачен в полном объёме' . (!empty($args['total_amount']) ? ' (' . $e($args['total_amount']) . ' ' . $e($args['currency'] ?? 'EUR') . ')' : '') . '. Дальнейших списаний не будет.';
        }
    }

    return implode("\n", $lines);
}
