<?php
/**
 * Admin settings page: Stripe checkout link, SEO, tracking pixels, webhook,
 * Telegram notification. Same save pattern as the landing plugin's
 * settings page -- one nonce shared by every form on the page, each form
 * gated on its own submit button's name (not the nonce alone), so
 * submitting one form never blanks out another form's saved values.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'VIP Tattoo — План курсу',
        'VIP Tattoo План',
        'manage_options',
        'vip-tattoo-plan-viewer',
        'vip_tattoo_plan_render_settings_page',
        'dashicons-media-document'
    );
});

function vip_tattoo_plan_settings_fields() {
    return [
        'vip_tattoo_plan_stripe_url'             => 'https://buy.stripe.com/fZucN57RO3TEgXu23C9AA00',
        'vip_tattoo_plan_cta_text'              => 'Открыть доступ к обучению',
        'vip_tattoo_plan_cta_secondary_text'    => 'Написать Виктории',
        'vip_tattoo_plan_telegram_contact_url'  => 'https://t.me/+48733341364',
        'vip_tattoo_plan_seo_title'             => 'План курса — VIP Tattoo School',
        'vip_tattoo_plan_seo_description'       => 'Полная программа обучения тату-мастеров с нуля до постоянных клиентов — все 15 блоков курса.',
        'vip_tattoo_plan_og_title'              => '',
        'vip_tattoo_plan_og_description'        => '',
        'vip_tattoo_plan_og_image_id'           => 0,
        'vip_tattoo_plan_canonical_url'         => '',
        'vip_tattoo_plan_robots'                => 'index,follow',
        'vip_tattoo_plan_schema_enabled'        => '1',
        'vip_tattoo_plan_ga4_id'                => '',
        'vip_tattoo_plan_gtm_id'                => '',
        'vip_tattoo_plan_meta_pixel_id'         => '',
        'vip_tattoo_plan_tiktok_pixel_id'       => '',
        'vip_tattoo_plan_pinterest_tag_id'      => '',
        'vip_tattoo_plan_linkedin_partner_id'   => '',
        'vip_tattoo_plan_webhook_url'           => '',
        'vip_tattoo_plan_telegram_bot_token'    => '',
        'vip_tattoo_plan_telegram_chat_id'      => '',
    ];
}

function vip_tattoo_plan_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $defaults = vip_tattoo_plan_settings_fields();

    if (isset($_POST['vip_tattoo_plan_save_settings']) && wp_verify_nonce($_POST['vip_tattoo_plan_settings_nonce'] ?? '', 'vip_tattoo_plan_settings')) {
        foreach ($defaults as $key => $default) {
            if (!isset($_POST[$key])) {
                // Checkboxes/number fields simply aren't present when
                // unchecked/empty -- fall back to the type-appropriate
                // default rather than skipping the update entirely, so
                // e.g. unchecking "enable schema" actually persists.
                update_option($key, is_int($default) ? 0 : ($key === 'vip_tattoo_plan_schema_enabled' ? '0' : $default));
                continue;
            }
            if (in_array($key, ['vip_tattoo_plan_seo_description', 'vip_tattoo_plan_og_description'], true)) {
                update_option($key, sanitize_textarea_field(wp_unslash($_POST[$key])));
            } elseif ($key === 'vip_tattoo_plan_og_image_id') {
                update_option($key, (int) $_POST[$key]);
            } elseif (in_array($key, ['vip_tattoo_plan_webhook_url', 'vip_tattoo_plan_telegram_contact_url', 'vip_tattoo_plan_stripe_url'], true)) {
                update_option($key, esc_url_raw(wp_unslash($_POST[$key])));
            } elseif ($key === 'vip_tattoo_plan_robots') {
                $allowed = ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'];
                $value = sanitize_text_field(wp_unslash($_POST[$key]));
                update_option($key, in_array($value, $allowed, true) ? $value : $default);
            } else {
                update_option($key, sanitize_text_field(wp_unslash($_POST[$key])));
            }
        }
        echo '<div class="notice notice-success"><p>Збережено.</p></div>';
    }

    $vals = [];
    foreach ($defaults as $key => $default) {
        $vals[$key] = get_option($key, $default);
    }

    $webhook_secret = get_option('vip_tattoo_plan_webhook_secret', '');
    ?>
    <div class="wrap">
        <h1>VIP Tattoo School — План курсу (презентація)</h1>

        <form method="post">
            <?php wp_nonce_field('vip_tattoo_plan_settings', 'vip_tattoo_plan_settings_nonce'); ?>

            <h2>Оплата і кнопки</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_stripe_url">Посилання на оплату (Stripe Payment Link)</label></th>
                    <td>
                        <input type="url" class="regular-text" id="vip_tattoo_plan_stripe_url" name="vip_tattoo_plan_stripe_url" value="<?php echo esc_attr($vals['vip_tattoo_plan_stripe_url']); ?>" />
                        <p class="description">Куди веде кнопка «<?php echo esc_html($vals['vip_tattoo_plan_cta_text']); ?>» — готове посилання Stripe Checkout (buy.stripe.com/...), відкривається в новій вкладці. Ніякого проміжного REST-роуту чи форми — Stripe сам приймає оплату.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_cta_text">Текст кнопки оплати</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_cta_text" name="vip_tattoo_plan_cta_text" value="<?php echo esc_attr($vals['vip_tattoo_plan_cta_text']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_cta_secondary_text">Текст другої кнопки</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_cta_secondary_text" name="vip_tattoo_plan_cta_secondary_text" value="<?php echo esc_attr($vals['vip_tattoo_plan_cta_secondary_text']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_telegram_contact_url">Посилання другої кнопки (особистий Telegram)</label></th>
                    <td>
                        <input type="url" class="regular-text" id="vip_tattoo_plan_telegram_contact_url" name="vip_tattoo_plan_telegram_contact_url" value="<?php echo esc_attr($vals['vip_tattoo_plan_telegram_contact_url']); ?>" />
                        <p class="description">Куди веде кнопка «<?php echo esc_html($vals['vip_tattoo_plan_cta_secondary_text']); ?>» — за замовчуванням особистий Telegram Вікторії з лендингу.</p>
                    </td>
                </tr>
            </table>

            <h2>SEO</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_seo_title">Meta Title</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_seo_title" name="vip_tattoo_plan_seo_title" value="<?php echo esc_attr($vals['vip_tattoo_plan_seo_title']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_seo_description">Meta Description</label></th>
                    <td><textarea id="vip_tattoo_plan_seo_description" name="vip_tattoo_plan_seo_description" rows="2" class="large-text"><?php echo esc_textarea($vals['vip_tattoo_plan_seo_description']); ?></textarea></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_og_title">OG Title (необов'язково)</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_og_title" name="vip_tattoo_plan_og_title" value="<?php echo esc_attr($vals['vip_tattoo_plan_og_title']); ?>" placeholder="за замовчуванням = Meta Title" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_og_description">OG Description (необов'язково)</label></th>
                    <td><textarea id="vip_tattoo_plan_og_description" name="vip_tattoo_plan_og_description" rows="2" class="large-text" placeholder="за замовчуванням = Meta Description"><?php echo esc_textarea($vals['vip_tattoo_plan_og_description']); ?></textarea></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_og_image_id">ID зображення OG (Медіафайли)</label></th>
                    <td><input type="number" class="regular-text" id="vip_tattoo_plan_og_image_id" name="vip_tattoo_plan_og_image_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_og_image_id']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_canonical_url">Canonical URL (необов'язково)</label></th>
                    <td><input type="url" class="regular-text" id="vip_tattoo_plan_canonical_url" name="vip_tattoo_plan_canonical_url" value="<?php echo esc_attr($vals['vip_tattoo_plan_canonical_url']); ?>" placeholder="за замовчуванням = URL цієї сторінки" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_robots">Robots</label></th>
                    <td>
                        <select id="vip_tattoo_plan_robots" name="vip_tattoo_plan_robots">
                            <?php foreach (['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'] as $opt): ?>
                                <option value="<?php echo esc_attr($opt); ?>" <?php selected($vals['vip_tattoo_plan_robots'], $opt); ?>><?php echo esc_html($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_schema_enabled">JSON-LD Course schema</label></th>
                    <td><label><input type="checkbox" id="vip_tattoo_plan_schema_enabled" name="vip_tattoo_plan_schema_enabled" value="1" <?php checked($vals['vip_tattoo_plan_schema_enabled'], '1'); ?> /> Вивести структуровані дані Course</label></td>
                </tr>
            </table>

            <h2>Піксели / аналітика</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_ga4_id">Google Analytics 4 (Measurement ID)</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_ga4_id" name="vip_tattoo_plan_ga4_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_ga4_id']); ?>" placeholder="G-XXXXXXXXXX" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_gtm_id">Google Tag Manager</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_gtm_id" name="vip_tattoo_plan_gtm_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_gtm_id']); ?>" placeholder="GTM-XXXXXXX" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_meta_pixel_id">Meta (Facebook) Pixel ID</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_meta_pixel_id" name="vip_tattoo_plan_meta_pixel_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_meta_pixel_id']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_tiktok_pixel_id">TikTok Pixel ID</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_tiktok_pixel_id" name="vip_tattoo_plan_tiktok_pixel_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_tiktok_pixel_id']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_pinterest_tag_id">Pinterest Tag ID</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_pinterest_tag_id" name="vip_tattoo_plan_pinterest_tag_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_pinterest_tag_id']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_linkedin_partner_id">LinkedIn Insight Tag (Partner ID)</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_linkedin_partner_id" name="vip_tattoo_plan_linkedin_partner_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_linkedin_partner_id']); ?>" /></td>
                </tr>
            </table>

            <h2>Вебхук (перегляди/завантаження)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_webhook_url">URL вебхука (Zapier / Make / CRM)</label></th>
                    <td>
                        <input type="url" class="regular-text" id="vip_tattoo_plan_webhook_url" name="vip_tattoo_plan_webhook_url" value="<?php echo esc_attr($vals['vip_tattoo_plan_webhook_url']); ?>" placeholder="https://hooks.zapier.com/..." />
                        <p class="description">
                            На кожен перегляд/завантаження сторінки плагін надішле сюди підписаний POST-запит (JSON).<br />
                            Секрет для перевірки підпису (HMAC-SHA256, заголовок <code>X-VIP-Tattoo-Signature</code>): <code><?php echo esc_html($webhook_secret); ?></code>
                        </p>
                    </td>
                </tr>
            </table>

            <h2>Telegram-сповіщення (при кліку на оплату)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="vip_tattoo_plan_telegram_bot_token">Bot Token (від @BotFather)</label></th>
                    <td><input type="password" class="regular-text" autocomplete="off" id="vip_tattoo_plan_telegram_bot_token" name="vip_tattoo_plan_telegram_bot_token" value="<?php echo esc_attr($vals['vip_tattoo_plan_telegram_bot_token']); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="vip_tattoo_plan_telegram_chat_id">Chat ID адміна</label></th>
                    <td><input type="text" class="regular-text" id="vip_tattoo_plan_telegram_chat_id" name="vip_tattoo_plan_telegram_chat_id" value="<?php echo esc_attr($vals['vip_tattoo_plan_telegram_chat_id']); ?>" /></td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="vip_tattoo_plan_save_settings" value="1" class="button button-primary">Зберегти налаштування</button>
            </p>
        </form>

        <hr />
        <h2>Останні перегляди / завантаження</h2>
        <?php vip_tattoo_plan_render_recent_events(); ?>
    </div>
    <?php
}
