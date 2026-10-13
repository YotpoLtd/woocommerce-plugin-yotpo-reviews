<?php
if( !defined('WP_UNINSTALL_PLUGIN') ) exit();
delete_option('yotpo_settings');

// Product sync (Core API V3): access token, sync state, throttle flags, stored Yotpo IDs and queued jobs.
// These names must match the constants in Yotpo_V3_Client (TOKEN_OPTION), Yotpo_Sync_State (STATE_OPTION,
// LOCK_OPTION), Yotpo_Catalog_Api (META_*) and Yotpo_Sync_Jobs (GROUP, PAUSE_TRANSIENT, RECHECK_TRANSIENT). This file runs without those classes loaded, so it cannot use them;
// when a constant changes, change it here too, or uninstall leaves data behind.
delete_option( 'wc_yotpo_v3_token' );
delete_option( 'wc_yotpo_product_sync' );
delete_option( 'wc_yotpo_product_sync_lock' );
delete_transient( 'wc_yotpo_product_sync_paused_until' );
delete_transient( 'wc_yotpo_product_sync_recheck' );
delete_post_meta_by_key( '_yotpo_v3_product_id' );
delete_post_meta_by_key( '_yotpo_v3_variant_id' );
delete_post_meta_by_key( '_yotpo_v3_blocklisted' );
delete_post_meta_by_key( '_yotpo_v3_product_hash' );
delete_post_meta_by_key( '_yotpo_v3_variant_hash' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'yotpo-product-sync' );
}

// Remove the private debug log directory (log, .htaccess and index.php guards); the log contains customer emails.
require_once ABSPATH . 'wp-admin/includes/file.php';
if ( WP_Filesystem() ) {
	global $wp_filesystem;
	$yotpo_upload_dir = wp_upload_dir( null, false );
	$yotpo_private_dir = trailingslashit( $yotpo_upload_dir['basedir'] ) . 'yotpo-social-reviews-for-woocommerce';
	if ( $wp_filesystem->is_dir( $yotpo_private_dir ) ) {
		$wp_filesystem->delete( $yotpo_private_dir, true );
	}
}
