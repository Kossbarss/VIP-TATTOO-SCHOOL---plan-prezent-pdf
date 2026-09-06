<?php
/**
 * Plugin Name: VIP Tattoo — План курсу (Презентація)
 * Description: Hosts the "План курсу" 23-slide interactive presentation on its own dedicated page template — self-contained SEO (title/description/OG/canonical/robots/JSON-LD), tracking-pixel integrations (GA4, GTM, Meta Pixel, TikTok Pixel, Pinterest Tag, LinkedIn Insight Tag), view/checkout-click/contact-click event tracking with an outbound webhook + optional Telegram notification, a direct Stripe Payment Link checkout button, and a settings page mirroring the coding standard of the VIP Tattoo landing plugin (nonces, sanitized fields, signature-verified inbound requests, idempotent event handling).
 * Version: 2.0.0
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
 * Finds the published page using this plugin's template, so the webhook
 * payload and any future canonical/SEO fallback can link to the real page
 * instead of an attachment or the bare home URL -- same pattern as the
 * landing plugin's vip_tattoo_find_page_url().
 */
function vip_tattoo_plan_page_url() {
    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'meta_key'       => '_wp_page_template',
        'meta_value'     => VIP_TATTOO_PLAN_TEMPLATE_SLUG,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);
    if ($pages) {
        $url = get_permalink($pages[0]);
        if ($url) return $url;
    }
    return home_url('/');
}

/**
 * Placeholder map for content/body-plan.html, resolved from the settings
 * page's saved options -- same "one array, one str_replace" pattern the
 * landing plugin's vip_tattoo_placeholders() uses for its own fragments.
 */
function vip_tattoo_plan_placeholders() {
    return [
        '{{ASSET_URL}}'          => VIP_TATTOO_PLAN_PLUGIN_URL . 'assets/',
        '{{STRIPE_CHECKOUT_URL}}' => esc_url(get_option('vip_tattoo_plan_stripe_url', 'https://buy.stripe.com/fZucN57RO3TEgXu23C9AA00')),
        '{{CTA_PRIMARY_TEXT}}'   => esc_html(get_option('vip_tattoo_plan_cta_text', 'Открыть доступ к обучению')),
        '{{CTA_SECONDARY_TEXT}}' => esc_html(get_option('vip_tattoo_plan_cta_secondary_text', 'Написать Виктории')),
        '{{CONTACT_URL}}'        => esc_url(get_option('vip_tattoo_plan_telegram_contact_url', 'https://t.me/+48733341364')),
    ];
}

/**
 * Renders the slide-deck body: content/body-plan.html with every
 * placeholder above substituted in, same file_get_contents()+str_replace()
 * approach the landing plugin uses for its own page fragments.
 */
function vip_tattoo_plan_render_body() {
    $file = VIP_TATTOO_PLAN_PLUGIN_DIR . 'content/body-plan.html';
    if (!file_exists($file)) return;

    $placeholders = vip_tattoo_plan_placeholders();
    echo str_replace(array_keys($placeholders), array_values($placeholders), file_get_contents($file));
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
    ?>
<script>
  window.VIP_TATTOO_PLAN_REST_URL = '<?php echo esc_js(rest_url('vip-tattoo-plan/v1/')); ?>';
  window.VIP_TATTOO_PLAN_NONCE = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
  window.VIP_TATTOO_PLAN_HAS_META_PIXEL = <?php echo trim(get_option('vip_tattoo_plan_meta_pixel_id', '')) ? 'true' : 'false'; ?>;
  window.VIP_TATTOO_PLAN_HAS_TIKTOK = <?php echo trim(get_option('vip_tattoo_plan_tiktok_pixel_id', '')) ? 'true' : 'false'; ?>;
  window.VIP_TATTOO_PLAN_HAS_GA4 = <?php echo trim(get_option('vip_tattoo_plan_ga4_id', '')) ? 'true' : 'false'; ?>;
</script>
    <?php
}
