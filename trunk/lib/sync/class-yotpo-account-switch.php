<?php

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin forgets when the merchant saves a different app key: everything it stored about the
 * previous Yotpo account. The products' and orders' Yotpo IDs belong to that account, so kept they would
 * send updates to IDs the new account doesn't have, and mark orders as sent that it never got (Send past
 * orders skips them). Afterwards the full catalog sync starts for the new account (Yotpo_Sync_Jobs::
 * maybe_start_initial_sync()), and the daily sweep only goes back to the switch: orders from before it
 * reach the new account only through Send past orders, so their customers aren't asked twice by surprise.
 */
class Yotpo_Account_Switch {

	/** Post meta the plugin stores per product and variation, per account. */
	const PRODUCT_META_KEYS = array(
		Yotpo_Catalog_Api::META_PRODUCT_ID,
		Yotpo_Catalog_Api::META_VARIANT_ID,
		Yotpo_Catalog_Api::META_PRODUCT_HASH,
		Yotpo_Catalog_Api::META_VARIANT_HASH,
		Yotpo_Catalog_Api::META_BLOCKLISTED,
	);

	/** Order meta the plugin stores per order, per account: in post meta, or in WooCommerce's order tables (HPOS). */
	const ORDER_META_KEYS = array(
		Yotpo_Order_Api::META_ORDER_ID,
		Yotpo_Order_Api::META_UPDATE_HASH,
	);

	/**
	 * Called when the settings are saved, before the new keys are used.
	 */
	public static function app_key_changed( $old_app_key, $new_app_key ) {
		if ( '' === (string) $old_app_key || (string) $old_app_key === (string) $new_app_key ) {
			return;
		}
		ytdbg( $old_app_key . ' to ' . $new_app_key, 'Yotpo account changed, stored Yotpo IDs forgotten:' );
		Yotpo_Sync_Jobs::cancel_group( Yotpo_Sync_Jobs::GROUP );
		Yotpo_Sync_Jobs::cancel_group( Yotpo_Order_Sync::GROUP );
		delete_option( Yotpo_Sync_State::STATE_OPTION );
		delete_option( Yotpo_Order_Backfill::STATE_OPTION );
		// Cancelling the order jobs removed the daily sweep too: have the next admin page load schedule it again.
		Yotpo_Order_Backfill::forget_sweep_check();
		delete_transient( Yotpo_Sync_Jobs::PAUSE_TRANSIENT );
		// A new start for the order counts, and for the sweep's window (it starts at enabled_at).
		update_option( Yotpo_Order_Sync::STATS_OPTION, array( 'enabled_at' => time() ), false );
		self::forget_meta();
		self::forget_order_transients();
	}

	private static function forget_meta() {
		foreach ( self::PRODUCT_META_KEYS as $key ) {
			delete_post_meta_by_key( $key );
		}
		self::forget_order_meta();
	}

	/** Also flushes WooCommerce's cached orders, which still carry the deleted rows. */
	private static function forget_order_meta() {
		foreach ( self::ORDER_META_KEYS as $key ) {
			delete_post_meta_by_key( $key );
		}
		self::forget_order_table_meta();
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'orders' );
		}
	}

	/** The same order meta in WooCommerce's own order tables, when the store uses them. Same query as uninstall.php. */
	private static function forget_order_table_meta() {
		global $wpdb;
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) !== $orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( self::ORDER_META_KEYS ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$orders_meta} WHERE meta_key IN ({$placeholders})", self::ORDER_META_KEYS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** "Yotpo doesn't have this order" and the sweep's "can't be sent", both about the old account. Same sweep as uninstall.php. */
	private static function forget_order_transients() {
		global $wpdb;
		$transient = '_transient_';
		foreach ( array( Yotpo_Order_Api::ABSENT_PREFIX, Yotpo_Order_Backfill::SETTLED_PREFIX ) as $prefix ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $transient . $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $names as $name ) {
				delete_transient( substr( $name, strlen( $transient ) ) );
			}
		}
	}
}
