<?php

function use_v3_widgets() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	return $settings['widget_version'] === 'v3';
}

function wc_yotpo_admin_settings() {
	add_action( 'admin_enqueue_scripts', 'wc_yotpo_admin_styles' );	
	$page = add_menu_page( 'Yotpo', 'Yotpo', 'manage_options', 'woocommerce-yotpo-settings-page', 'wc_display_yotpo_admin_page', 'none', null );			
}

function wc_yotpo_redirect() {
	if ( get_option('wc_yotpo_just_installed', false)) {
		delete_option('wc_yotpo_just_installed');
		wp_redirect( ( ( is_ssl() || force_ssl_admin() ) ? str_replace( 'http:', 'https:', admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) ) : str_replace( 'https:', 'http:', admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) ) ) );
		exit;
	}	
}
