<?php

defined( 'ABSPATH' ) || exit;

function use_v3_widgets() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	return $settings['widget_version'] === 'v3';
}

function wc_yotpo_admin_settings() {
	add_action( 'admin_enqueue_scripts', 'wc_yotpo_admin_styles' );
	add_action( 'admin_head', 'wc_yotpo_menu_icon_style' );
	$page = add_menu_page( 'Yotpo', 'Yotpo', 'manage_options', 'woocommerce-yotpo-settings-page', 'wc_display_yotpo_admin_page', 'none', null );
}

// Yotpo brand mark (assets/images/yotpo-menu-icon.svg: the brand file without its white square) as the admin menu icon.
// Painted with inline CSS rather than passed to add_menu_page(): WordPress recolors SVG menu icons to the
// admin gray, which drops the brand blue. Inline, so there is no stylesheet for browsers to cache.
function wc_yotpo_menu_icon_style() {
	$icon_url = plugins_url( 'assets/images/yotpo-menu-icon.svg', dirname( __DIR__, 2 ) . '/wc_yotpo.php' );
	echo '<style>#toplevel_page_woocommerce-yotpo-settings-page .wp-menu-image{background:url("' . esc_url( $icon_url ) . '") center / 20px 20px no-repeat;}</style>';
}

// Single idiom for "is WooCommerce active" used across the plugin, instead of each
// call site re-implementing its own class_exists()/defined() check.
function wc_yotpo_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

// After activation, an admin's next wp-admin page opens the Yotpo settings, once.
function wc_yotpo_redirect() {
	if ( ! get_option( 'wc_yotpo_just_installed', false ) || ! wc_yotpo_can_redirect_after_activation() ) {
		return;
	}
	delete_option( 'wc_yotpo_just_installed' );
	// wc_yotpo_init() never registers the settings page (admin_menu -> wc_yotpo_admin_settings)
	// when WooCommerce is inactive, so redirecting there would land on an unregistered
	// menu slug and produce an access-denied error instead of a no-op. After a bulk activation,
	// WordPress shows the plugins list, as for other plugins.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress's own flag after a bulk activation, only checked for presence.
	if ( ! wc_yotpo_is_woocommerce_active() || isset( $_GET['activate-multi'] ) ) {
		return;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) );
	exit;
}

// Registered on admin_init, which also fires for admin-ajax.php: only an admin's page load redirects.
function wc_yotpo_can_redirect_after_activation() {
	return ! wp_doing_ajax() && current_user_can( 'manage_options' );
}
