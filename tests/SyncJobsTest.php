<?php
/**
 * The background side of the product sync: which product changes queue jobs, the full catalog sync,
 * retries after a rate limit, and the kill switch.
 */
class SyncJobsTest extends Yotpo_Test_Case {

	public function test_full_sync_starts_once_per_app_key() {
		Yotpo_Sync_Jobs::maybe_start_initial_sync();
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ), 'Page 1 queued' );
		$this->assertSame( Yotpo_Sync_State::STATUS_RUNNING, Yotpo_Product_Sync::state()['status'] );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ), 'Not queued again' );
	}

	public function test_full_sync_sends_every_published_product_and_completes() {
		$products = array( $this->create_product(), $this->create_product( 'Leather Belt', 'YT-ACC-002' ), $this->create_variable_product() );
		$draft    = $this->create_product( 'Draft', 'YT-DRAFT' );
		$draft->set_status( 'draft' );
		$draft->save();
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP ); // The jobs the saves queued.
		$this->route_catalog_ok();

		$this->assertTrue( Yotpo_Product_Sync::start_full_sync() );
		$this->run_jobs();

		$state = Yotpo_Product_Sync::state();
		$this->assertSame( Yotpo_Sync_State::STATUS_COMPLETE, $state['status'] );
		$this->assertSame( 3, (int) $state['total'], 'Published products only' );
		$this->assertSame( 3, (int) $state['synced'] );
		$this->assertSame( 0, (int) $state['failed'] );
		$this->assertSame( array(), $this->pending_jobs() );
		foreach ( $products as $product ) {
			$this->assertGreaterThan( 0, (int) get_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
		}
	}

	public function test_saving_a_product_queues_one_job() {
		$beanie = $this->create_product();
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );

		$beanie->save();
		$beanie->save();

		$jobs = $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_PRODUCT );
		$this->assertCount( 1, $jobs, 'Two saves, one job' );
		$this->assertSame( array( $beanie->get_id(), 1 ), array_values( reset( $jobs )->get_args() ) );
	}

	public function test_saving_a_variation_queues_its_parent() {
		$hoodie = $this->create_variable_product();
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );

		wc_get_product( $hoodie->get_children()[0] )->save();

		$jobs = $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_PRODUCT );
		$this->assertCount( 1, $jobs );
		$this->assertSame( $hoodie->get_id(), reset( $jobs )->get_args()[0] );
	}

	public function test_rate_limited_job_is_retried_after_retry_after_and_pauses_all_jobs() {
		$beanie = $this->create_product();
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );
		$this->route_tokens();
		$this->route( 'POST', '#^/products$#', function () {
			return $this->respond( 429, null, array( 'retry-after' => '9' ) );
		} );
		$before = time();

		Yotpo_Sync_Jobs::run_product_job( $beanie->get_id(), 1 );

		$jobs = $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_PRODUCT );
		$this->assertCount( 1, $jobs );
		$job = reset( $jobs );
		$this->assertSame( array( $beanie->get_id(), 2 ), array_values( $job->get_args() ), 'Attempt 2' );
		$at = $job->get_schedule()->get_date()->getTimestamp();
		$this->assertGreaterThanOrEqual( $before + 9, $at );
		$this->assertLessThanOrEqual( time() + 10, $at );
		$this->assertGreaterThan( time(), (int) get_transient( Yotpo_Sync_Jobs::PAUSE_TRANSIENT ), 'Every job waits' );
	}

	public function test_last_attempt_is_not_retried_again() {
		$beanie = $this->create_product();
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );
		$this->route_tokens();
		$this->route( 'POST', '#^/products$#', function () {
			return $this->respond( 429, null, array( 'retry-after' => '9' ) );
		} );

		Yotpo_Sync_Jobs::run_product_job( $beanie->get_id(), Yotpo_Sync_Jobs::MAX_ATTEMPTS );

		$this->assertSame( array(), $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_PRODUCT ) );
	}

	public function test_deleting_a_variation_queues_a_discontinue_job() {
		$hoodie       = $this->create_variable_product();
		$variation_id = $hoodie->get_children()[0];
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );

		Yotpo_Sync_Jobs::queue_deleted_post( $variation_id );

		$jobs = $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_VARIANT_GONE );
		$this->assertCount( 1, $jobs );
		$this->assertSame( array( $variation_id, $hoodie->get_id(), 1 ), array_values( reset( $jobs )->get_args() ) );
	}

	public function test_kill_switch_filter_stops_all_queueing() {
		$beanie = $this->create_product();
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );
		add_filter( 'wc_yotpo_product_sync_enabled', '__return_false' );

		$this->assertFalse( Yotpo_Product_Sync::enabled() );
		$beanie->save();
		Yotpo_Sync_Jobs::maybe_start_initial_sync();

		$this->assertSame( array(), $this->pending_jobs() );
		$this->assertWPError( Yotpo_Product_Sync::start_full_sync() );
		remove_filter( 'wc_yotpo_product_sync_enabled', '__return_false' );
	}

	/** Yotpo accepts everything: products and variants are created with new IDs. */
	private function route_catalog_ok() {
		$next = 1000;
		$this->route_tokens();
		$this->route( 'GET', '#^/products$#', function () {
			return $this->respond( 200, array( 'products' => array(), 'pagination' => array( 'next_page_info' => null ) ) );
		} );
		$this->route( 'POST', '#^/products$#', function () use ( &$next ) {
			return $this->respond( 201, array( 'product' => array( 'yotpo_id' => ++$next ) ) );
		} );
		$this->route( 'POST', '#^/products/\d+/variants$#', function () use ( &$next ) {
			return $this->respond( 201, array( 'variant' => array( 'yotpo_id' => (string) ++$next ) ) );
		} );
		$this->route( 'PATCH', '#^/products/\d+(/variants/\d+)?$#', function () {
			return $this->respond( 200 );
		} );
	}
}
