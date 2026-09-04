<?php
/**
 * Fully self-contained page: prints its own <html>/<head>/<body> rather
 * than calling get_header()/get_footer(), the same "independent landing
 * page" approach the VIP Tattoo landing plugin's own templates use — this
 * page never inherits the active theme's header, footer, or menu, exactly
 * per the requirement that it publish independently of the rest of the
 * site's pages.
 */

if (!defined('ABSPATH')) exit;

$pdf_url = vip_tattoo_plan_pdf_url();
$cta_primary = get_option('vip_tattoo_plan_cta_text', 'Открыть доступ к обучению');
$cta_secondary = get_option('vip_tattoo_plan_cta_secondary_text', 'Написать Виктории');
$contact_url = get_option('vip_tattoo_plan_telegram_contact_url', 'https://t.me/+48733341364');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php vip_tattoo_plan_render_seo(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url(VIP_TATTOO_PLAN_PLUGIN_URL . 'css/style.css'); ?>">
<?php vip_tattoo_plan_render_pixels(); ?>
</head>
<body>
<?php vip_tattoo_plan_render_globals(); ?>
  <div class="vtp-wrap">
    <div class="vtp-logo">V</div>
    <div class="vtp-eyebrow">VIP Tattoo School</div>
    <h1>Полная программа обучения<br>тату-мастеров с нуля</h1>
    <p class="vtp-lead">Полное содержание и план всех 15 блоков курса — от первого включения машинки до именного диплома. Открой презентацию ниже или скачай PDF.</p>
    <div class="vtp-btn-row">
      <button type="button" class="vtp-btn vtp-btn-primary" id="vtpOpenCheckout"><?php echo esc_html($cta_primary); ?></button>
      <a class="vtp-btn vtp-btn-secondary" data-vtp-event="contact_click" href="<?php echo esc_url($contact_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($cta_secondary); ?></a>
    </div>
    <div class="vtp-viewer">
      <iframe src="<?php echo esc_url($pdf_url); ?>" title="План курсу VIP Tattoo School" loading="lazy"></iframe>
    </div>
    <p class="vtp-download-link"><a href="<?php echo esc_url($pdf_url); ?>" data-vtp-event="download" download>Скачать PDF-версию плана</a></p>
    <div class="vtp-footer-note">ViP tattoo school · Виктория Поникарова</div>
  </div>

  <!-- ============ CHECKOUT POPUP ============ -->
  <div class="vtp-popup-overlay" id="vtpPopupOverlay"></div>
  <div class="vtp-popup-card" id="vtpPopupCard" role="dialog" aria-modal="true" aria-labelledby="vtpPopupTitle">
    <button class="vtp-popup-close" id="vtpPopupClose" aria-label="Закрыть" type="button">&times;</button>
    <h3 id="vtpPopupTitle">Открыть доступ к обучению</h3>
    <p class="vtp-popup-sub">Оставь email и телефон — пришлём ссылку на оплату и доступ к курсу в Telegram сразу после оплаты.</p>
    <form class="vtp-popup-form" id="vtpCheckoutForm">
      <input type="email" name="email" placeholder="Твой email" required />
      <input type="tel" name="phone" placeholder="Твой номер телефона" required />
      <button type="submit" class="vtp-btn vtp-btn-primary vtp-btn-block">Перейти к оплате</button>
    </form>
    <p class="vtp-popup-footnote"><strong>3 дня гарантия возврата</strong> — без лишних вопросов</p>
  </div>

<script src="<?php echo esc_url(VIP_TATTOO_PLAN_PLUGIN_URL . 'js/track.js'); ?>" defer></script>
<script src="<?php echo esc_url(VIP_TATTOO_PLAN_PLUGIN_URL . 'js/checkout.js'); ?>" defer></script>
</body>
</html>
