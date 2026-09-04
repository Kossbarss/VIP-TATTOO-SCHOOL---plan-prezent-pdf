<?php
/**
 * Plugin Name: VIP Tattoo — План курсу (Презентація)
 * Description: Hosts the "План курсу" PDF presentation on its own dedicated page template — self-contained SEO (title/description/OG/canonical/robots/JSON-LD), tracking-pixel integrations (GA4, GTM, Meta Pixel, TikTok Pixel, Pinterest Tag, LinkedIn Insight Tag), view/download event tracking with an outbound webhook + optional Telegram notification, and a settings page mirroring the coding standard of the VIP Tattoo landing plugin (nonces, sanitized fields, signature-verified inbound requests, idempotent event handling).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

define('VIP_TATTOO_PLAN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VIP_TATTOO_PLAN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIP_TATTOO_PLAN_TEMPLATE_SLUG', 'vip-tattoo-plan-viewer.php');

require_once VIP_TATTOO_PLAN_PLUGIN_DIR . 'includes/settings.php';
require_once VIP_TATTOO_PLAN_PLUGIN_DIR . 'includes/tracking.php';

/**
 * Single page template this plugin ships. Kept as a function (not a bare
 * constant) so it can be reused by both the template-registration filter
 * below and template_include, the same pattern the landing plugin uses
 * for its own multi-template list — here it's just one entry.
 */
function vip_tattoo_plan_page_templates() {
    return [
        VIP_TATTOO_PLAN_TEMPLATE_SLUG => 'VIP Tattoo — План курсу (презентація)',
    ];
}

add_filter('theme_page_templates', function ($templates) {
    return array_merge($templates, vip_tattoo_plan_page_templates());
});

add_filter('template_include', function ($template) {
    if (!is_page()) return $template;

    $selected = get_page_template_slug(get_the_ID());
    if (array_key_exists($selected, vip_tattoo_plan_page_templates())) {
        $custom = VIP_TATTOO_PLAN_PLUGIN_DIR . 'templates/' . $selected;
        if (file_exists($custom)) return $custom;
    }

    return $template;
});

/**
 * Resolves the PDF URL the template should embed/link to: an uploaded
 * Media Library attachment (preferred, set on the settings page) with a
 * fallback to the copy of plan-kursu.pdf bundled in assets/, so the page
 * still works out of the box before anyone touches the settings.
 */
function vip_tattoo_plan_pdf_url() {
    $attachment_id = (int) get_option('vip_tattoo_plan_pdf_attachment_id', 0);
    if ($attachment_id) {
        $url = wp_get_attachment_url($attachment_id);
        if ($url) return $url;
    }
    return VIP_TATTOO_PLAN_PLUGIN_URL . 'assets/plan-kursu.pdf';
}

/**
 * SEO <head> block: title/description/canonical/robots/Open Graph/Twitter
 * Card + an optional Course JSON-LD block. Deliberately independent of
 * whatever SEO plugin (Yoast, Rank Math, etc.) the site may also have
 * active elsewhere — this page template renders its own <head> rather
 * than relying on wp_head(), so there is nothing else to conflict with.
 */
function vip_tattoo_plan_render_seo() {
    $title       = get_option('vip_tattoo_plan_seo_title', 'План курсу — VIP Tattoo School');
    $description = get_option('vip_tattoo_plan_seo_description', 'Полная программа обучения тату-мастеров с нуля до постоянных клиентов — все 15 блоков курса.');
    $og_title    = get_option('vip_tattoo_plan_og_title', '') ?: $title;
    $og_desc     = get_option('vip_tattoo_plan_og_description', '') ?: $description;
    $canonical   = get_option('vip_tattoo_plan_canonical_url', '') ?: get_permalink();
    $robots      = get_option('vip_tattoo_plan_robots', 'index,follow');
    $og_image_id = (int) get_option('vip_tattoo_plan_og_image_id', 0);
    $og_image    = $og_image_id ? wp_get_attachment_url($og_image_id) : '';

    echo '<title>' . esc_html($title) . "</title>\n";
    echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    echo '<meta name="robots" content="' . esc_attr($robots) . '">' . "\n";
    echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";

    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($og_desc) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . "\n";
    if ($og_image) {
        echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($og_title) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($og_desc) . '">' . "\n";
    if ($og_image) {
        echo '<meta name="twitter:image" content="' . esc_url($og_image) . '">' . "\n";
    }

    if (get_option('vip_tattoo_plan_schema_enabled', '1') === '1') {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Course',
            'name'        => $title,
            'description' => $description,
            'provider'    => [
                '@type' => 'Organization',
                'name'  => 'VIP Tattoo School',
                'sameAs' => home_url('/'),
            ],
        ];
        echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
    }
}

/**
 * Tracking-pixel <head>/<body> snippets. Each provider is only printed
 * when its ID is actually configured, so an unused integration adds zero
 * markup rather than an empty/broken snippet.
 */
function vip_tattoo_plan_render_pixels() {
    $ga4_id      = trim(get_option('vip_tattoo_plan_ga4_id', ''));
    $gtm_id      = trim(get_option('vip_tattoo_plan_gtm_id', ''));
    $meta_pixel  = trim(get_option('vip_tattoo_plan_meta_pixel_id', ''));
    $tiktok_id   = trim(get_option('vip_tattoo_plan_tiktok_pixel_id', ''));
    $pinterest   = trim(get_option('vip_tattoo_plan_pinterest_tag_id', ''));
    $linkedin_id = trim(get_option('vip_tattoo_plan_linkedin_partner_id', ''));

    if ($gtm_id) {
        ?>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo esc_js($gtm_id); ?>');</script>
        <?php
    }

    if ($ga4_id) {
        ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($ga4_id); ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo esc_js($ga4_id); ?>');</script>
        <?php
    }

    if ($meta_pixel) {
        ?>
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','<?php echo esc_js($meta_pixel); ?>');fbq('track','PageView');</script>
        <?php
    }

    if ($tiktok_id) {
        ?>
<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};ttq.load('<?php echo esc_js($tiktok_id); ?>');ttq.page();}(window,document,'ttq');</script>
        <?php
    }

    if ($pinterest) {
        ?>
<script>!function(e){if(!window.pintrk){window.pintrk=function(){window.pintrk.queue.push(Array.prototype.slice.call(arguments))};var n=window.pintrk;n.queue=[],n.version="3.0";var t=document.createElement("script");t.async=!0,t.src=e;var r=document.getElementsByTagName("script")[0];r.parentNode.insertBefore(t,r)}}("https://s.pinimg.com/ct/core.js");pintrk('load','<?php echo esc_js($pinterest); ?>');pintrk('page');</script>
        <?php
    }

    if ($linkedin_id) {
        ?>
<script type="text/javascript">_linkedin_partner_id="<?php echo esc_js($linkedin_id); ?>";window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);</script>
<script type="text/javascript">(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName("script")[0];var b=document.createElement("script");b.type="text/javascript";b.async=true;b.src="https://snap.licdn.com/li.lms-analytics/insight.min.js";s.parentNode.insertBefore(b,s);})(window.lintrk);</script>
        <?php
    }
}

/**
 * Inline globals consumed by js/track.js — same "print right after <body>"
 * ordering guarantee the landing plugin's vip_tattoo_render_globals() uses
 * for its own React bundles, for the same reason: the tracking script
 * reads these synchronously the moment it runs near the end of <body>.
 */
function vip_tattoo_plan_render_globals() {
    // The checkout REST route lives in the *separate* vip-tattoo-landing
    // plugin's own namespace ('vip-tattoo/v1', registered by its
    // includes/payments.php) -- both plugins run on the same WordPress
    // install, so this is just rest_url() pointed at another active
    // plugin's already-registered route, not a duplicated payment
    // implementation. Overridable in settings in case that plugin's
    // namespace ever changes.
    $checkout_base = trim(get_option('vip_tattoo_plan_checkout_rest_base', ''));
    if (!$checkout_base) $checkout_base = rest_url('vip-tattoo/v1/');
    ?>
<script>
  window.VIP_TATTOO_PLAN_REST_URL = '<?php echo esc_js(rest_url('vip-tattoo-plan/v1/')); ?>';
  window.VIP_TATTOO_PLAN_NONCE = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
  window.VIP_TATTOO_CHECKOUT_REST_BASE = '<?php echo esc_js($checkout_base); ?>';
  window.VIP_TATTOO_PLAN_HAS_META_PIXEL = <?php echo trim(get_option('vip_tattoo_plan_meta_pixel_id', '')) ? 'true' : 'false'; ?>;
  window.VIP_TATTOO_PLAN_HAS_TIKTOK = <?php echo trim(get_option('vip_tattoo_plan_tiktok_pixel_id', '')) ? 'true' : 'false'; ?>;
  window.VIP_TATTOO_PLAN_HAS_GA4 = <?php echo trim(get_option('vip_tattoo_plan_ga4_id', '')) ? 'true' : 'false'; ?>;
</script>
    <?php
}
