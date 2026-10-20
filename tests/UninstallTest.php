<?php
/**
 * uninstall.php removes everything the plugin stored. It runs without the plugin's classes, so it
 * repeats their option, meta and group names: this test catches a name changed in one place only.
 */
class UninstallTest extends Yotpo_Test_Case {

	public function test_uninstall_removes_all_plugin_data() {
		$product = $this->create_product();
		foreach ( array( Yotpo_Catalog_Api::META_PRODUCT_ID, Yotpo_Catalog_Api::META_VARIANT_ID, Yotpo_Catalog_Api::META_PRODUCT_HASH, Yotpo_Catalog_Api::META_VARIANT_HASH, Yotpo_Catalog_Api::META_BLOCKLISTED ) as $key ) {
			update_post_meta( $product->get_id(), $key, '1' );
		}
		$order = $this->create_order( array( array( $product, 1 ) ) );
		$order->update_meta_data( Yotpo_Order_Api::META_ORDER_ID, 123 );
		$order->update_meta_data( Yotpo_Order_Api::META_UPDATE_HASH, 'x' );
		$order->save_meta_data();
		update_option( 'wc_yotpo_v3_token', array( 'token' => 'x' ) );
		update_option( 'wc_yotpo_just_installed', true );
		update_option( 'native_star_ratings_enabled', 'yes' );
		update_option( Yotpo_Sync_State::STATE_OPTION, array( 'status' => 'complete' ) );
		update_option( Yotpo_Sync_State::LOCK_OPTION, '1:x' );
		set_transient( Yotpo_Sync_Jobs::PAUSE_TRANSIENT, time() + 60, 60 );
		set_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT, 1, 300 );
		set_transient( Yotpo_Order_Api::ABSENT_PREFIX . $order->get_id(), 1, 600 );
		update_option( Yotpo_Order_Sync::LOCK_OPTION, '1:x' );
		Yotpo_Product_Sync::start_full_sync();
		$this->assertNotEmpty( $this->pending_jobs() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'yotpo-social-reviews-for-woocommerce/wc_yotpo.php' );
		}
		include dirname( __DIR__ ) . '/trunk/uninstall.php';

		foreach ( array( 'yotpo_settings', 'wc_yotpo_v3_token', 'wc_yotpo_just_installed', 'native_star_ratings_enabled', Yotpo_Sync_State::STATE_OPTION, Yotpo_Sync_State::LOCK_OPTION, Yotpo_Order_Sync::LOCK_OPTION ) as $option ) {
			$this->assertFalse( get_option( $option ), "Option $option left behind" );
		}
		foreach ( array( Yotpo_Sync_Jobs::PAUSE_TRANSIENT, Yotpo_Sync_Jobs::RECHECK_TRANSIENT ) as $transient ) {
			$this->assertFalse( get_transient( $transient ), "Transient $transient left behind" );
		}
		$this->assertFalse( get_transient( Yotpo_Order_Api::ABSENT_PREFIX . $order->get_id() ), 'Order lookup transient left behind' );
		global $wpdb;
		$absent_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( Yotpo_Order_Api::ABSENT_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, $absent_rows, 'Order lookup transient rows left behind (value or timeout)' );
		$meta_left = array_filter(
			array_keys( get_post_meta( $product->get_id() ) ),
			function ( $key ) {
				return 0 === strpos( $key, '_yotpo_v3_' );
			}
		);
		$this->assertSame( array(), array_values( $meta_left ), 'Yotpo meta left behind' );
		$order_meta_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", Yotpo_Order_Api::META_ORDER_ID, Yotpo_Order_Api::META_UPDATE_HASH ) );
		$this->assertSame( 0, $order_meta_left, 'Order meta left behind in post meta (the order storage of this test)' );
		$this->assertSame( array(), $this->pending_jobs(), 'Jobs left queued' );
	}

	/**
	 * With high-performance order storage, WooCommerce keeps order meta in its own table, which the test
	 * bootstrap creates. Orders made by create_order() are in post storage here, so the rows are inserted
	 * directly.
	 */
	public function test_uninstall_removes_order_meta_from_the_order_tables() {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_orders_meta';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Created by tests/bootstrap.php' );
		foreach ( array( Yotpo_Order_Api::META_ORDER_ID, Yotpo_Order_Api::META_UPDATE_HASH, '_not_from_yotpo' ) as $key ) {
			$wpdb->insert( $table, array( 'order_id' => 987654, 'meta_key' => $key, 'meta_value' => 'x' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'yotpo-social-reviews-for-woocommerce/wc_yotpo.php' );
		}
		include dirname( __DIR__ ) . '/trunk/uninstall.php';

		$left = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$table} WHERE order_id = %d", 987654 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( array( '_not_from_yotpo' ), $left, "Only the plugin's order meta is removed from the order tables" );
	}
}
