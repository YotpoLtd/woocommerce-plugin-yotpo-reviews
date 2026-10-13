<?php
/**
 * The merchant's "Sync products to Yotpo" setting.
 */
class SyncSettingTest extends Yotpo_Test_Case {

	public function test_settings_saved_before_the_setting_existed_count_as_on() {
		$settings = get_option( 'yotpo_settings' );
		unset( $settings['product_sync_enabled'] );
		update_option( 'yotpo_settings', $settings );

		$this->assertTrue( Yotpo_Product_Sync::setting_on() );
		$this->assertTrue( Yotpo_Product_Sync::enabled() );
		$this->assertTrue( wc_yotpo_get_default_settings()['product_sync_enabled'], 'On by default for new installs' );
	}

	public function test_no_credentials_means_no_sync() {
		$this->set_setting( 'secret', '' );
		$this->assertFalse( Yotpo_Product_Sync::enabled() );
		$this->assertSame( 'Starts once the app key and secret token are saved.', Yotpo_Product_Sync::status_summary() );
	}

	public function test_turning_it_off_cancels_queued_jobs_and_stops_a_running_sync() {
		Yotpo_Product_Sync::start_full_sync();
		$this->create_product();
		$this->assertNotEmpty( $this->pending_jobs() );

		$this->set_setting( 'product_sync_enabled', false );
		Yotpo_Product_Sync::setting_changed( true, false );

		$this->assertSame( array(), $this->pending_jobs() );
		$this->assertSame( Yotpo_Sync_State::STATUS_STOPPED, Yotpo_Product_Sync::state()['status'] );
		$this->assertFalse( Yotpo_Product_Sync::enabled() );
		$this->assertSame( 'Off. Products are not sent to Yotpo.', Yotpo_Product_Sync::status_summary() );
	}

	public function test_while_off_nothing_is_queued() {
		$this->set_setting( 'product_sync_enabled', false );

		$this->create_product();
		Yotpo_Sync_Jobs::maybe_start_initial_sync();

		$this->assertSame( array(), $this->pending_jobs() );
	}

	public function test_turning_it_back_on_starts_a_full_sync() {
		$this->set_setting( 'product_sync_enabled', false );
		Yotpo_Product_Sync::setting_changed( true, false );

		$this->set_setting( 'product_sync_enabled', true );
		Yotpo_Product_Sync::setting_changed( false, true );

		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ) );
		$this->assertSame( Yotpo_Sync_State::STATUS_RUNNING, Yotpo_Product_Sync::state()['status'] );
		$this->assertStringStartsWith( 'Full sync running', Yotpo_Product_Sync::status_summary() );
	}

	public function test_saving_without_a_change_does_nothing() {
		Yotpo_Product_Sync::setting_changed( true, true );
		$this->assertSame( array(), $this->pending_jobs() );
	}

	public function test_status_line_after_a_full_sync() {
		update_option(
			Yotpo_Sync_State::STATE_OPTION,
			array( 'app_key' => self::APP_KEY, 'status' => 'complete', 'finished' => 1791810498, 'synced' => 10, 'failed' => 2, 'total' => 12, 'last_error' => 'HTTP 400: bad price' )
		);
		$this->assertMatchesRegularExpression( '/^Last full sync: .+\. 10 synced, 2 failed\. Last error: HTTP 400: bad price$/', Yotpo_Product_Sync::status_summary() );
	}
}
