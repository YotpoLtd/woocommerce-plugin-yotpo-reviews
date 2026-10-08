<?php

defined( 'ABSPATH' ) || exit;

function use_v3_widgets() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	return $settings['widget_version'] === 'v3';
}

function wc_yotpo_admin_settings() {
	add_action( 'admin_enqueue_scripts', 'wc_yotpo_admin_styles' );	
	$page = add_menu_page( 'Yotpo', 'Yotpo', 'manage_options', 'woocommerce-yotpo-settings-page', 'wc_display_yotpo_admin_page', 'none', null );			
}

// Single idiom for "is WooCommerce active" used across the plugin, instead of each
// call site re-implementing its own class_exists()/defined() check.
function wc_yotpo_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

function wc_yotpo_redirect() {
	if ( get_option('wc_yotpo_just_installed', false)) {
		delete_option('wc_yotpo_just_installed');
		// wc_yotpo_init() never registers the settings page (admin_menu -> wc_yotpo_admin_settings)
		// when WooCommerce is inactive, so redirecting there would land on an unregistered
		// menu slug and produce an access-denied error instead of a no-op.
		if ( ! wc_yotpo_is_woocommerce_active() ) {
			return;
		}
		wp_redirect( ( ( is_ssl() || force_ssl_admin() ) ? str_replace( 'http:', 'https:', admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) ) : str_replace( 'https:', 'http:', admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) ) ) );
		exit;
	}	
}
