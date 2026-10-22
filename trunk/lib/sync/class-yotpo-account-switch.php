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
		// Each class forgets its own state.
		Yotpo_Sync_Jobs::account_switched();
		Yotpo_Sync_State::forget();
		Yotpo_Order_Sync::account_switched();
		Yotpo_Order_Backfill::account_switched();
		self::forget_meta();
		self::forget_order_transients();
	}

	/**
	 * One DELETE per table rather than a call per key, row or transient: the settings save waits for it, and
	 * a large store has many rows. The object cache doesn't know about the rows deleted behind its back, so
	 * the groups that carried them are flushed.
	 */
	private static function forget_meta() {
		global $wpdb;
		$keys = array_merge( self::PRODUCT_META_KEYS, self::ORDER_META_KEYS );
		self::delete_meta_rows( $wpdb->postmeta, $keys );
		$orders_meta = $wpdb->prefix . 'wc_orders_meta'; // WooCommerce's own order tables (HPOS), when the store uses them. Same query as uninstall.php.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) === $orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::delete_meta_rows( $orders_meta, self::ORDER_META_KEYS );
		}
		// 'orders': WooCommerce's cached orders still carry the deleted rows.
		self::flush_cache_groups( array( 'post_meta', 'orders' ) );
	}

	private static function delete_meta_rows( $table, array $keys ) {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE meta_key IN ({$placeholders})", $keys ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * "Yotpo doesn't have this order" and the sweep's "can't be sent", both about the old account: the
	 * transients and their timeout rows, found by prefix. Same sweep as uninstall.php.
	 */
	private static function forget_order_transients() {
		global $wpdb;
		$patterns = array();
		foreach ( array( Yotpo_Order_Api::ABSENT_PREFIX, Yotpo_Order_Backfill::SETTLED_PREFIX ) as $prefix ) {
			foreach ( array( '_transient_', '_transient_timeout_' ) as $kind ) {
				$patterns[] = $wpdb->esc_like( $kind . $prefix ) . '%';
			}
		}
		$where = implode( ' OR ', array_fill( 0, count( $patterns ), 'option_name LIKE %s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE {$where}", $patterns ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		self::flush_cache_groups( array( 'options' ) );
	}

	/** Flushes the given object cache groups, or the whole cache when it can't flush a group. */
	private static function flush_cache_groups( array $groups ) {
		if ( ! function_exists( 'wp_cache_supports' ) || ! wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush();
			return;
		}
		foreach ( $groups as $group ) {
			wp_cache_flush_group( $group );
		}
	}
}
