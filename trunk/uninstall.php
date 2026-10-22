<?php
if( !defined('WP_UNINSTALL_PLUGIN') ) exit();
delete_option('yotpo_settings');
// Set on activation: the one-time redirect to the settings, and WooCommerce's star rating setting
// from before (restored on deactivation, which runs before uninstall).
delete_option('wc_yotpo_just_installed');
delete_option('native_star_ratings_enabled');

// Product sync (Core API V3): access token, sync state, throttle flags, stored Yotpo IDs and queued jobs.
// These names must match the constants in Yotpo_V3_Client (TOKEN_OPTION), Yotpo_Sync_State (STATE_OPTION,
// LOCK_OPTION), Yotpo_Catalog_Api (META_*), Yotpo_Order_Api (META_*, ABSENT_PREFIX), Yotpo_Order_Backfill (STATE_OPTION, SWEEP_CHECK, SETTLED_PREFIX) and Yotpo_Sync_Jobs (GROUP, PAUSE_TRANSIENT, RECHECK_TRANSIENT). This file runs without those classes loaded, so it cannot use them;
// when a constant changes, change it here too, or uninstall leaves data behind.
// Yotpo_Account_Switch (a different app key forgets the old account's data) runs the same order table delete and transient sweep as below: change them together.
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
// Orders (Yotpo_Order_Api META_*): in post meta, or in WooCommerce's own order tables when the store uses them.
delete_post_meta_by_key( '_yotpo_v3_order_id' );
delete_post_meta_by_key( '_yotpo_v3_order_hash' );
delete_option( 'wc_yotpo_order_sync' ); // Yotpo_Order_Sync::STATS_OPTION
delete_option( 'wc_yotpo_order_sync_lock' ); // Yotpo_Order_Sync::LOCK_OPTION
delete_option( 'wc_yotpo_order_backfill' ); // Yotpo_Order_Backfill::STATE_OPTION
delete_transient( 'wc_yotpo_order_sweep_check' ); // Yotpo_Order_Backfill::SWEEP_CHECK
global $wpdb;
// One transient per order that Yotpo didn't have (Yotpo_Order_Api::ABSENT_PREFIX), and one per order the daily sweep
// could not send (Yotpo_Order_Backfill::SETTLED_PREFIX). The names carry the order ID, so they are found by prefix and
// deleted as transients (which also drops their timeout rows and cached copies). With an external object cache they
// live outside the options table and expire within ten minutes (days, for the sweep's).
foreach ( array( 'wc_yotpo_order_absent_', 'wc_yotpo_order_settled_' ) as $yotpo_transient_prefix ) {
	$yotpo_order_transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . $yotpo_transient_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $yotpo_order_transients as $yotpo_order_transient ) {
		delete_transient( substr( $yotpo_order_transient, strlen( '_transient_' ) ) );
	}
}
$yotpo_orders_meta = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $yotpo_orders_meta ) ) === $yotpo_orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$yotpo_orders_meta} WHERE meta_key IN (%s, %s)", '_yotpo_v3_order_id', '_yotpo_v3_order_hash' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'yotpo-product-sync' );
	as_unschedule_all_actions( '', array(), 'yotpo-order-sync' ); // Yotpo_Order_Sync::GROUP
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
