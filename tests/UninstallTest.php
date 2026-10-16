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
		update_option( 'wc_yotpo_v3_token', array( 'token' => 'x' ) );
		update_option( 'wc_yotpo_just_installed', true );
		update_option( 'native_star_ratings_enabled', 'yes' );
		update_option( Yotpo_Sync_State::STATE_OPTION, array( 'status' => 'complete' ) );
		update_option( Yotpo_Sync_State::LOCK_OPTION, '1:x' );
		set_transient( Yotpo_Sync_Jobs::PAUSE_TRANSIENT, time() + 60, 60 );
		set_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT, 1, 300 );
		Yotpo_Product_Sync::start_full_sync();
		$this->assertNotEmpty( $this->pending_jobs() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'yotpo-social-reviews-for-woocommerce/wc_yotpo.php' );
		}
		include dirname( __DIR__ ) . '/trunk/uninstall.php';

		foreach ( array( 'yotpo_settings', 'wc_yotpo_v3_token', 'wc_yotpo_just_installed', 'native_star_ratings_enabled', Yotpo_Sync_State::STATE_OPTION, Yotpo_Sync_State::LOCK_OPTION ) as $option ) {
			$this->assertFalse( get_option( $option ), "Option $option left behind" );
		}
		foreach ( array( Yotpo_Sync_Jobs::PAUSE_TRANSIENT, Yotpo_Sync_Jobs::RECHECK_TRANSIENT ) as $transient ) {
			$this->assertFalse( get_transient( $transient ), "Transient $transient left behind" );
		}
		$meta_left = array_filter(
			array_keys( get_post_meta( $product->get_id() ) ),
			function ( $key ) {
				return 0 === strpos( $key, '_yotpo_v3_' );
			}
		);
		$this->assertSame( array(), array_values( $meta_left ), 'Yotpo meta left behind' );
		$this->assertSame( array(), $this->pending_jobs(), 'Jobs left queued' );
	}
}
