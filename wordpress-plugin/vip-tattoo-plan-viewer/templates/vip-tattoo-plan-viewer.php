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
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php vip_tattoo_plan_render_seo(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700;1,900&family=Nunito:ital,wght@0,400;0,600;0,700;0,800;1,300;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url(VIP_TATTOO_PLAN_PLUGIN_URL . 'css/plan-viewer.css'); ?>">
<?php vip_tattoo_plan_render_pixels(); ?>
</head>
<body>
<?php vip_tattoo_plan_render_globals(); ?>
<?php vip_tattoo_plan_render_body(); ?>
<script src="<?php echo esc_url(VIP_TATTOO_PLAN_PLUGIN_URL . 'js/track.js'); ?>" defer></script>
</body>
</html>
