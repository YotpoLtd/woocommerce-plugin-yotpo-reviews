<?php
/**
 * Send past orders and the daily sweep (Yotpo_Order_Backfill): pages of orders are looked up in one
 * request each, and only what Yotpo lacks is created.
 */
class OrderBackfillTest extends Yotpo_Test_Case {

	/** @var array Yotpo's orders by external ID. */
	private $orders = array();

	/** @var int|null When set, POST /orders answers this code. */
	private $create_fails = null;

	/** @var int Creates answered before create_fails applies. */
	private $creates_before_failing = 0;

	private $next_id = 9000;

	/** @var WC_Product A product Yotpo has. */
	private $product;

	public function set_up() {
		parent::set_up();
		$this->orders                 = array();
		$this->create_fails           = null;
		$this->creates_before_failing = 0;
		// A job's time budget must not depend on how fast the machine is; the tests of the budget set their own.
		add_filter(
			'wc_yotpo_order_job_budget',
			function () {
				return HOUR_IN_SECONDS;
			}
		);
		$this->route_tokens();
		$this->route_fake_orders();
		$this->product = $this->create_product();
		update_post_meta( $this->product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, 4242 );
	}

	// -----------------------------------------------------------------------------------------
	// Send past orders
	// -----------------------------------------------------------------------------------------

	public function test_count_covers_fulfilled_orders_in_the_window() {
		$this->past_order( 5 );
		$this->past_order( 20 );
		$this->past_order( 5, 'processing' );

		$this->assertSame( 1, Yotpo_Order_Backfill::count( 10 ) );
		$this->assertSame( 2, Yotpo_Order_Backfill::count( 30 ) );
	}

	public function test_a_run_sends_only_what_yotpo_lacks() {
		$sent_by_1x = $this->past_order( 10 );
		$this->orders[ $sent_by_1x->get_id() ] = array( 'yotpo_id' => 77, 'external_id' => (string) $sent_by_1x->get_id() );
		$sent_live = $this->past_order( 8 );
		$sent_live->update_meta_data( Yotpo_Order_Api::META_ORDER_ID, 88 );
		$sent_live->save_meta_data();
		$missing   = $this->past_order( 6 );
		$too_old   = $this->past_order( 40 );
		$no_name   = $this->past_order( 4, 'completed', array( 'first_name' => '' ) );

		$this->assertTrue( Yotpo_Order_Backfill::start( 30 ) );
		$this->run_order_jobs();

		$this->assertSame( array( 'POST /access_tokens', 'GET /orders', 'POST /orders' ), $this->calls(), 'One lookup for the page, one create' );
		$this->assertSame( array( $sent_by_1x->get_id(), $missing->get_id() ), array_keys( $this->orders ) );
		$this->assertArrayNotHasKey( $too_old->get_id(), $this->orders );
		$this->assertArrayNotHasKey( $no_name->get_id(), $this->orders );
		$state = Yotpo_Order_Backfill::state();
		$this->assertSame( Yotpo_Order_Backfill::STATUS_COMPLETE, $state['status'] );
		$this->assertSame( array( 4, 4, 1, 2, 1, 0 ), array( $state['total'], $state['checked'], $state['sent'], $state['already'], $state['skipped'], $state['failed'] ) );
		$this->assertStringContainsString( '1 sent, 2 already in Yotpo, 1 skipped, 0 failed.', Yotpo_Order_Backfill::status_summary() );
		$this->assertSame( 0, Yotpo_Order_Sync::totals()['sent'], 'Past orders are not counted with the live ones' );
	}

	public function test_the_fulfillment_date_is_when_the_order_was_completed() {
		$order = $this->past_order( 12 );

		Yotpo_Order_Backfill::start( 30 );
		$this->run_order_jobs();

		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $order->get_date_completed()->getTimestamp() ), $this->orders[ $order->get_id() ]['fulfillments'][0]['fulfillment_date'] );
	}

	public function test_150_orders_take_two_pages_of_one_lookup_each() {
		for ( $i = 0; $i < 150; $i++ ) {
			$this->past_order( 3 );
		}

		Yotpo_Order_Backfill::start( 10 );
		$this->run_order_jobs();

		$this->assertSame( 2, count( array_keys( $this->calls(), 'GET /orders', true ) ) );
		$this->assertSame( 150, count( $this->orders ) );
		$this->assertSame( 150, Yotpo_Order_Backfill::state()['sent'] );
	}

	public function test_a_retry_resumes_where_the_page_stopped() {
		$orders = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$orders[] = $this->past_order( 3 );
		}
		$this->creates_before_failing = 1;
		$this->create_fails           = 503;

		Yotpo_Order_Backfill::start( 10 );
		$this->run_order_jobs();
		$this->assertCount( 1, $this->orders, 'The first create went through' );
		$this->assertSame( array( array( 1, 2, 1 ) ), $this->page_job_args(), 'The retry starts at the second order' );

		$this->create_fails = null;
		$this->run_due_later();

		$state = Yotpo_Order_Backfill::state();
		$this->assertCount( 3, $this->orders );
		$this->assertSame( array( 3, 3, 0 ), array( $state['checked'], $state['sent'], $state['already'] ), 'No order sent or counted twice' );
		$this->assertSame( Yotpo_Order_Backfill::STATUS_COMPLETE, $state['status'] );
	}

	public function test_a_rejected_order_counts_as_failed_and_the_run_goes_on() {
		$this->past_order( 3 );
		$this->past_order( 3 );
		$this->create_fails = 400;

		Yotpo_Order_Backfill::start( 10 );
		$this->run_order_jobs();

		$state = Yotpo_Order_Backfill::state();
		$this->assertSame( array( 2, 0, 2 ), array( $state['checked'], $state['sent'], $state['failed'] ) );
		$this->assertSame( 'warning', Yotpo_Order_Backfill::status_tone() );
	}

	public function test_days_must_be_1_to_180() {
		foreach ( array( 0, 181, -5 ) as $days ) {
			$this->assertSame( 'yotpo_backfill_days', Yotpo_Order_Backfill::start( $days )->get_error_code(), "$days days" );
		}
		$this->assertTrue( Yotpo_Order_Backfill::start( 180 ) );
	}

	public function test_one_run_at_a_time() {
		Yotpo_Order_Backfill::start( 10 );

		$this->assertSame( 'yotpo_backfill_busy', Yotpo_Order_Backfill::start( 10 )->get_error_code() );
	}

	public function test_no_run_while_sending_is_off() {
		$this->set_setting( 'order_sync_enabled', false );

		$this->assertSame( 'yotpo_order_sync_off', Yotpo_Order_Backfill::start( 10 )->get_error_code() );
	}

	public function test_turning_sending_off_stops_a_run() {
		$this->past_order( 3 );
		Yotpo_Order_Backfill::start( 10 );

		$this->set_setting( 'order_sync_enabled', false );
		Yotpo_Order_Sync::setting_changed( true, false );

		$this->assertSame( Yotpo_Order_Backfill::STATUS_STOPPED, Yotpo_Order_Backfill::state()['status'] );
		$this->assertSame( array(), $this->order_jobs() );
		$this->assertStringContainsString( 'was turned off', Yotpo_Order_Backfill::status_summary() );
	}

	public function test_a_page_job_that_uses_its_time_budget_hands_the_rest_of_the_page_on() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->past_order( 3 );
		}
		add_filter( 'wc_yotpo_order_job_budget', '__return_zero' ); // Every job stops after one order.

		Yotpo_Order_Backfill::start( 10 );
		$page_jobs = array_keys( $this->order_jobs( Yotpo_Order_Backfill::HOOK_PAGE ) );
		ActionScheduler_QueueRunner::instance()->process_action( reset( $page_jobs ), 'phpunit' );

		$this->assertCount( 1, $this->orders, 'One order created, then the job gave up the rest of its page' );
		$this->assertSame( Yotpo_Order_Backfill::STATUS_RUNNING, Yotpo_Order_Backfill::state()['status'] );
		$this->assertSame( array( array( 1, 1, 1 ) ), $this->page_job_args(), 'The rest is queued as a job of its own, starting at the second order' );

		$this->run_order_jobs();

		$state = Yotpo_Order_Backfill::state();
		$this->assertCount( 3, $this->orders );
		$this->assertSame( array( 3, 3, 0, 0 ), array( $state['checked'], $state['sent'], $state['already'], $state['failed'] ), 'No order sent or counted twice' );
		$this->assertSame( Yotpo_Order_Backfill::STATUS_COMPLETE, $state['status'] );
	}

	public function test_a_page_job_put_off_by_a_429_pause_is_queued_again() {
		$this->past_order( 3 );
		Yotpo_Order_Backfill::start( 10 );
		set_transient( Yotpo_Sync_Jobs::PAUSE_TRANSIENT, time() + 120, 120 );

		$page_jobs = array_keys( $this->order_jobs( Yotpo_Order_Backfill::HOOK_PAGE ) );
		ActionScheduler_QueueRunner::instance()->process_action( reset( $page_jobs ), 'phpunit' );

		$this->assertSame( array( array( 1, 1, 0 ) ), $this->page_job_args(), 'Queued again, for after the pause, though its arguments are those of the job that put it off' );
		$this->assertSame( array(), $this->orders, 'Nothing is sent during the pause' );
	}

	public function test_a_run_whose_job_died_can_be_started_again() {
		$this->past_order( 3 );
		Yotpo_Order_Backfill::start( 10 );
		as_unschedule_all_actions( '', array(), Yotpo_Order_Sync::GROUP ); // The page job died: nothing is queued or running.

		Yotpo_Order_Backfill::end_if_stalled();
		$this->assertTrue( Yotpo_Order_Backfill::running(), 'Not before it has been quiet for a while' );

		$state                  = Yotpo_Order_Backfill::state();
		$state['started']       = time() - Yotpo_Order_Backfill::STALL_AFTER - 60;
		$state['last_activity'] = $state['started'];
		update_option( Yotpo_Order_Backfill::STATE_OPTION, $state );

		$this->assertTrue( Yotpo_Order_Backfill::running(), 'Reading it changes nothing' );
		Yotpo_Order_Backfill::end_if_stalled();
		$this->assertFalse( Yotpo_Order_Backfill::running() );
		$this->assertSame( Yotpo_Order_Backfill::STATUS_STOPPED, Yotpo_Order_Backfill::state()['status'] );
		$this->assertStringContainsString( 'ended without finishing', Yotpo_Order_Backfill::status_summary() );
		$this->assertTrue( Yotpo_Order_Backfill::start( 10 ) );
		$this->run_order_jobs();
		$this->assertSame( 1, Yotpo_Order_Backfill::state()['sent'] );
	}

	public function test_a_quiet_run_with_a_job_still_queued_is_not_stalled() {
		Yotpo_Order_Backfill::start( 10 );
		$state                  = Yotpo_Order_Backfill::state();
		$state['started']       = time() - Yotpo_Order_Backfill::STALL_AFTER - 60;
		$state['last_activity'] = $state['started'];
		update_option( Yotpo_Order_Backfill::STATE_OPTION, $state );

		Yotpo_Order_Backfill::end_if_stalled();
		$this->assertTrue( Yotpo_Order_Backfill::running(), 'Its page job is still queued, for example behind a 429 pause' );
	}

	// -----------------------------------------------------------------------------------------
	// Daily sweep
	// -----------------------------------------------------------------------------------------

	public function test_the_sweep_is_scheduled_daily_once() {
		Yotpo_Order_Backfill::maybe_schedule_sweep();
		delete_transient( Yotpo_Order_Backfill::SWEEP_CHECK );
		Yotpo_Order_Backfill::maybe_schedule_sweep();

		$jobs = $this->order_jobs( Yotpo_Order_Backfill::HOOK_SWEEP );
		$this->assertCount( 1, $jobs );
		$job = reset( $jobs );
		$this->assertTrue( $job->get_schedule()->is_recurring() );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $job->get_schedule()->get_date()->getTimestamp(), 'First run within a day' );
	}

	public function test_the_sweep_is_checked_in_the_admin_not_on_every_request() {
		$this->assertNotFalse( has_action( 'admin_init', array( 'Yotpo_Order_Backfill', 'maybe_schedule_sweep' ) ) );
		$this->assertFalse( has_action( 'init', array( 'Yotpo_Order_Backfill', 'maybe_schedule_sweep' ) ), 'Storefront requests do no extra reads' );
	}

	public function test_turning_sending_on_has_the_sweep_checked_again() {
		Yotpo_Order_Backfill::maybe_schedule_sweep();
		$this->assertNotFalse( get_transient( Yotpo_Order_Backfill::SWEEP_CHECK ) );

		Yotpo_Order_Sync::setting_changed( false, true );

		$this->assertFalse( get_transient( Yotpo_Order_Backfill::SWEEP_CHECK ) );
	}

	public function test_no_sweep_while_sending_is_off() {
		$this->set_setting( 'order_sync_enabled', false );

		Yotpo_Order_Backfill::maybe_schedule_sweep();

		$this->assertSame( array(), $this->order_jobs( Yotpo_Order_Backfill::HOOK_SWEEP ) );
	}

	public function test_the_sweep_sends_a_recent_order_yotpo_lacks() {
		$order = $this->past_order( 2 );

		$this->sweep();

		$this->assertArrayHasKey( $order->get_id(), $this->orders );
		$this->assertSame( 1, Yotpo_Order_Sync::totals()['sent'], 'Counted with the live orders' );
	}

	public function test_the_sweep_costs_no_request_when_every_order_was_sent() {
		foreach ( array( 1, 2, 3 ) as $days ) {
			$order = $this->past_order( $days );
			$order->update_meta_data( Yotpo_Order_Api::META_ORDER_ID, 100 + $days );
			$order->save_meta_data();
		}

		$this->sweep();

		$this->assertSame( array(), $this->calls() );
	}

	public function test_the_sweep_finds_orders_sent_by_1x_and_stores_their_ids() {
		$order = $this->past_order( 2 );
		$this->orders[ $order->get_id() ] = array( 'yotpo_id' => 55, 'external_id' => (string) $order->get_id() );

		$this->sweep();

		$this->assertNotContains( 'POST /orders', $this->calls() );
		$this->assertSame( 55, (int) wc_get_order( $order->get_id() )->get_meta( Yotpo_Order_Api::META_ORDER_ID ) );
	}

	public function test_the_sweep_skips_orders_changed_before_its_window() {
		$old = $this->past_order( 10 );

		$this->sweep();

		$this->assertArrayNotHasKey( $old->get_id(), $this->orders );
	}

	public function test_the_sweep_skips_orders_from_before_sending_was_turned_on() {
		$order = $this->past_order( 2 );
		update_option( Yotpo_Order_Sync::STATS_OPTION, array( 'enabled_at' => time() - DAY_IN_SECONDS ) );

		$this->sweep();

		$this->assertArrayNotHasKey( $order->get_id(), $this->orders );
	}

	public function test_the_sweep_skips_orders_completed_before_the_window_even_if_changed_since() {
		update_option( Yotpo_Order_Sync::STATS_OPTION, array( 'enabled_at' => time() - DAY_IN_SECONDS ) );
		$before_enabled = $this->past_order( 2 );
		$this->complete_at( $before_enabled, time() - 3 * DAY_IN_SECONDS ); // Completed while sending was off, edited just now.
		$before_window = $this->past_order( 2 );
		$this->complete_at( $before_window, time() - 9 * DAY_IN_SECONDS );
		$fulfilled_now = $this->past_order( 2 );
		$this->complete_at( $fulfilled_now, time() - HOUR_IN_SECONDS );

		$this->sweep();

		$this->assertSame( array( $fulfilled_now->get_id() ), array_keys( $this->orders ) );
	}

	public function test_the_sweep_finds_an_order_that_reached_another_fulfilled_status_in_the_window() {
		$this->set_setting( 'yotpo_order_status', 'wc-processing' );
		$order = $this->past_order( 2, 'processing' );
		$order->set_date_completed( time() - 20 * DAY_IN_SECONDS ); // Completed long ago, moved to this status just now.
		$order->save();
		as_unschedule_all_actions( '', array(), Yotpo_Order_Sync::GROUP );
		$this->set_date_modified( $order, time() - HOUR_IN_SECONDS );
		$old = $this->past_order( 10, 'processing' );

		$this->sweep();

		$this->assertSame( array( $order->get_id() ), array_keys( $this->orders ) );
	}

	public function test_the_sweep_leaves_orders_it_could_not_send_for_good_alone_until_they_change() {
		$rejected = $this->past_order( 2 );
		$no_email = $this->past_order( 2, 'completed', array( 'email' => '' ) );
		$this->create_fails = 400;

		$this->sweep();

		$this->assertSame( 1, Yotpo_Order_Sync::totals()['failed'] );
		$requests = count( $this->calls() );
		$this->assertGreaterThan( 0, $requests );

		$this->sweep();
		$this->sweep();

		$this->assertCount( $requests, $this->calls(), 'No lookup and no create for them again' );
		$this->assertSame( 1, Yotpo_Order_Sync::totals()['failed'], 'The failure is counted once' );

		// Edited since: worth another try.
		$this->create_fails = null;
		$rejected->set_customer_note( 'Fixed' );
		$rejected->save();
		as_unschedule_all_actions( '', array(), Yotpo_Order_Sync::GROUP );
		$this->sweep();

		$this->assertArrayHasKey( $rejected->get_id(), $this->orders );
	}

	public function test_a_sweep_page_that_uses_its_time_budget_goes_on_in_a_new_job() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->past_order( 2 );
		}
		add_filter( 'wc_yotpo_order_job_budget', '__return_zero' );

		$this->sweep();

		$this->assertCount( 3, $this->orders );
		$this->assertSame( 3, Yotpo_Order_Sync::totals()['sent'], 'No order counted twice' );
	}

	public function test_a_sweep_page_put_off_by_a_429_pause_is_queued_again() {
		$this->past_order( 2 );
		Yotpo_Order_Backfill::run_sweep_job();
		set_transient( Yotpo_Sync_Jobs::PAUSE_TRANSIENT, time() + 120, 120 );

		$jobs = array_keys( $this->order_jobs( Yotpo_Order_Backfill::HOOK_SWEEP_PAGE ) );
		ActionScheduler_QueueRunner::instance()->process_action( reset( $jobs ), 'phpunit' );

		$this->assertCount( 1, $this->order_jobs( Yotpo_Order_Backfill::HOOK_SWEEP_PAGE ), 'Queued again, for after the pause' );
		$this->assertSame( array(), $this->orders );
	}

	public function test_a_failure_in_the_sweep_shows_in_the_status_line() {
		$this->past_order( 2 );
		$this->create_fails = 400;

		$this->sweep();

		$this->assertSame( 1, Yotpo_Order_Sync::totals()['failed'] );
	}

	// -----------------------------------------------------------------------------------------

	/**
	 * A fulfilled order placed, completed and last changed $days days ago (as orders sent after the
	 * fact are), in $status. Saved directly, so no order job is queued.
	 */
	private function past_order( $days, $status = 'completed', array $billing = array() ) {
		$order = $this->create_order( array( array( $this->product, 1 ) ), $status, $billing );
		$when  = time() - $days * DAY_IN_SECONDS;
		$order->set_date_created( $when );
		if ( 'completed' === $status ) {
			$order->set_date_completed( $when );
		}
		$order->save();
		as_unschedule_all_actions( '', array(), Yotpo_Order_Sync::GROUP ); // The status change queued a send.
		$this->set_date_modified( $order, $when );
		return wc_get_order( $order->get_id() );
	}

	/** Changes when the order was completed, and edits it just now: it is modified now. */
	private function complete_at( $order, $timestamp ) {
		$order->set_date_completed( $timestamp );
		$order->save();
		as_unschedule_all_actions( '', array(), Yotpo_Order_Sync::GROUP );
		$this->set_date_modified( $order, time() );
	}

	/** WooCommerce sets the date modified on every save, so it is written to the database directly. */
	private function set_date_modified( $order, $timestamp ) {
		global $wpdb;
		$gmt   = gmdate( 'Y-m-d H:i:s', $timestamp );
		$local = get_date_from_gmt( $gmt );
		if ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $gmt ), array( 'id' => $order->get_id() ) );
		} else {
			$wpdb->update( $wpdb->posts, array( 'post_modified' => $local, 'post_modified_gmt' => $gmt ), array( 'ID' => $order->get_id() ) );
		}
		clean_post_cache( $order->get_id() );
		wp_cache_flush();
	}

	private function sweep() {
		Yotpo_Order_Backfill::run_sweep_job();
		$this->run_order_jobs();
	}

	/** The arguments of the queued page jobs of a run. */
	private function page_job_args() {
		return array_values(
			array_map(
				function ( $job ) {
					return $job->get_args();
				},
				$this->order_jobs( Yotpo_Order_Backfill::HOOK_PAGE )
			)
		);
	}

	private function order_jobs( $hook = null ) {
		return $this->pending_jobs( $hook, Yotpo_Order_Sync::GROUP );
	}

	/** Runs the order jobs that are due, including any they queue for now. */
	private function run_order_jobs() {
		for ( $round = 0; $round < 10; $round++ ) {
			$due = array_filter(
				$this->order_jobs(),
				function ( $job ) {
					$date = $job->get_schedule()->get_date();
					return ! $job->get_schedule()->is_recurring() && ( ! $date || $date->getTimestamp() <= time() );
				}
			);
			if ( ! $due ) {
				return;
			}
			foreach ( array_keys( $due ) as $action_id ) {
				ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'phpunit' );
			}
		}
	}

	/** Runs the jobs scheduled for later (retries) as if their time had come. */
	private function run_due_later() {
		foreach ( array_keys( $this->order_jobs( Yotpo_Order_Backfill::HOOK_PAGE ) ) as $action_id ) {
			ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'phpunit' );
		}
		$this->run_order_jobs();
	}

	private function route_fake_orders() {
		$this->route( 'GET', '#^/orders$#', function ( $request ) {
			parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
			$ids = explode( ',', $query['external_ids'] );
			if ( count( $ids ) > 100 || array_diff( array_keys( $query ), array( 'external_ids' ) ) ) {
				return $this->respond( 400 );
			}
			$found = array();
			foreach ( $ids as $id ) {
				if ( isset( $this->orders[ $id ] ) ) {
					$found[] = array( 'yotpo_id' => $this->orders[ $id ]['yotpo_id'], 'external_id' => (string) $id );
				}
			}
			return $this->respond( 200, array( 'orders' => $found ) );
		} );
		$this->route( 'POST', '#^/orders$#', function ( $request ) {
			if ( $this->create_fails && $this->creates_before_failing <= 0 ) {
				return $this->respond( $this->create_fails );
			}
			--$this->creates_before_failing;
			$order                                   = $request['body']['order'];
			$this->orders[ $order['external_id'] ] = array_merge( $order, array( 'yotpo_id' => ++$this->next_id ) );
			return $this->respond( 201, array( 'order' => array( 'yotpo_id' => $this->next_id ) ) );
		} );
	}
}
