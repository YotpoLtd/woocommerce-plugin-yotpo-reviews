<?php
/**
 * Settings saved by an older version (GitHub issue #69) and the one-time redirect after activation
 * (GitHub issue #26).
 */
class UpgradeAndActivationTest extends Yotpo_Test_Case {

	/** What a store that last saved its settings on 1.1.8 has: none of the keys added since. */
	private function old_settings() {
		return array(
			'app_key'            => self::APP_KEY,
			'secret'             => 'test-secret',
			'widget_location'    => 'footer',
			'language_code'      => 'es',
			'yotpo_order_status' => 'wc-processing',
		);
	}

	public function test_settings_saved_by_an_old_version_get_the_missing_keys() {
		update_option( 'yotpo_settings', $this->old_settings() );

		$settings = get_option( 'yotpo_settings' );

		foreach ( array_keys( wc_yotpo_get_default_settings() ) as $key ) {
			$this->assertArrayHasKey( $key, $settings );
		}
		$this->assertSame( wc_yotpo_get_default_settings()['v3_widgets_enables'], $settings['v3_widgets_enables'], 'Nested keys too' );
		$this->assertSame( 'es', $settings['language_code'], 'Saved values win' );
		$this->assertSame( 'wc-processing', $settings['yotpo_order_status'] );
	}

	public function test_missing_keys_keep_the_behaviour_they_had_before_they_existed() {
		update_option( 'yotpo_settings', $this->old_settings() );

		$settings = get_option( 'yotpo_settings' );

		$this->assertSame( 'v2', $settings['widget_version'] );
		$this->assertFalse( use_v3_widgets(), 'Still on the v2 widgets' );
		$this->assertFalse( $settings['yotpo_language_as_site'], 'The saved language_code is used' );
		$this->assertFalse( $settings['disable_native_review_system'], 'Native reviews are kept' );
		$this->assertFalse( $settings['show_submit_past_orders'] );
	}

	public function test_saved_values_win_over_the_legacy_behaviour() {
		update_option(
			'yotpo_settings',
			array_merge(
				$this->old_settings(),
				array(
					'widget_version'               => 'v3',
					'yotpo_language_as_site'       => true,
					'disable_native_review_system' => true,
				)
			)
		);

		$settings = get_option( 'yotpo_settings' );

		$this->assertTrue( use_v3_widgets() );
		$this->assertTrue( $settings['yotpo_language_as_site'] );
		$this->assertTrue( $settings['disable_native_review_system'] );
	}

	public function test_saved_nested_values_win_over_the_defaults() {
		$settings                                         = $this->old_settings();
		$settings['v3_widgets_enables']                   = array( 'reviews_widget_product' => false );
		update_option( 'yotpo_settings', $settings );

		$read = get_option( 'yotpo_settings' );

		$this->assertFalse( $read['v3_widgets_enables']['reviews_widget_product'] );
		$this->assertArrayHasKey( 'star_rating_product', $read['v3_widgets_enables'], 'The rest from the defaults' );
	}

	public function test_old_settings_render_the_settings_page_and_sync_without_errors() {
		update_option( 'yotpo_settings', $this->old_settings() );
		require_once dirname( __DIR__ ) . '/trunk/templates/wc-yotpo-settings.php';
		require_once dirname( __DIR__ ) . '/trunk/lib/yotpo-api/Yotpo.php';

		// PHPUnit turns PHP warnings (an undefined index) into failures.
		ob_start();
		wc_display_yotpo_settings();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="yotpo_settings_form"', $html );
		$this->assertTrue( Yotpo_Product_Sync::enabled() );
		$this->assertFalse( Yotpo_Product_Sync::tags_on() );
		$this->assertArrayHasKey( 'name', Yotpo_Product_Sync::product_payload( $this->create_product() ) );
	}

	public function test_activation_redirect_is_kept_for_a_shopper() {
		update_option( 'wc_yotpo_just_installed', true );

		// A shopper's request (the front end) right after activation.
		wc_yotpo_redirect();

		$this->assertTrue( (bool) get_option( 'wc_yotpo_just_installed' ), 'Not redirected, not used up' );
	}

	public function test_activation_redirect_is_kept_for_a_non_admin_in_wp_admin() {
		update_option( 'wc_yotpo_just_installed', true );
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		wc_yotpo_redirect();

		$this->assertTrue( (bool) get_option( 'wc_yotpo_just_installed' ), 'Not redirected, not used up' );
	}

	public function test_activation_redirect_is_kept_for_an_admin_ajax_request() {
		update_option( 'wc_yotpo_just_installed', true );
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_redirect',
			function () {
				throw new RuntimeException( 'unexpected redirect' );
			}
		);

		wc_yotpo_redirect();

		$this->assertTrue( (bool) get_option( 'wc_yotpo_just_installed' ), 'Not redirected, not used up' );
	}

	public function test_activation_redirect_takes_an_admin_to_the_settings_once() {
		update_option( 'wc_yotpo_just_installed', true );
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$redirected_to = null;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirected_to ) {
				$redirected_to = $location;
				throw new RuntimeException( 'redirected' ); // Instead of the exit() after it.
			}
		);

		try {
			wc_yotpo_redirect();
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ), $redirected_to );
		$this->assertFalse( get_option( 'wc_yotpo_just_installed' ), 'Once' );
	}

	public function test_bulk_activation_uses_the_redirect_up_without_redirecting() {
		update_option( 'wc_yotpo_just_installed', true );
		set_current_screen( 'plugins' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['activate-multi'] = 'true';
		add_filter(
			'wp_redirect',
			function () {
				throw new RuntimeException( 'unexpected redirect' );
			}
		);

		wc_yotpo_redirect();
		unset( $_GET['activate-multi'] );

		$this->assertFalse( get_option( 'wc_yotpo_just_installed' ) );
	}

	public function tear_down() {
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}
}
