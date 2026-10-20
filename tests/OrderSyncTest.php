<?php
/**
 * Orders are sent from background jobs (Yotpo_Order_Sync): the hooks that queue them, the jobs, the
 * "Send orders to Yotpo" switch, retries and the shared 429 pause, and the status line.
 */
class OrderSyncTest extends Yotpo_Test_Case {

	/** @var array Yotpo's orders by external ID. */
	private $orders = array();

	/** @var int|null When set, POST /orders answers this code. */
	private $create_fails = null;

	private $next_id = 7000;

	/** @var WC_Product A product Yotpo has, with its Yotpo ID stored. */
	private $product;

	public function set_up() {
		parent::set_up();
		$this->orders      = array();
		$this->create_fails = null;
		$this->route_tokens();
		$this->route_fake_orders();
		$this->product = $this->create_product();
		update_post_meta( $this->product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, 4242 );
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP ); // The product save queued a product job.
	}

	public function test_completing_an_order_queues_a_job_and_calls_nothing() {
		$order = $this->create_order( array( array( $this->product, 1 ) ) );

		$this->assertSame( array(), $this->calls(), 'No Yotpo call while the order is saved, legacy or V3' );
		$jobs = $this->order_jobs( Yotpo_Order_Sync::HOOK_FULFILLED );
		$this->assertCount( 1, $jobs );
		$this->assertSame( $order->get_id(), reset( $jobs )->get_args()[0] );
	}

	public function test_the_job_sends_the_order() {
		$order = $this->create_order( array( array( $this->product, 2 ) ) );

		$this->run_order_jobs();

		$this->assertSame( array( 'POST /access_tokens', 'GET /orders', 'POST /orders' ), $this->calls() );
		$this->assertSame( 2, $this->orders[ $order->get_id() ]['line_items'][0]['quantity'] );
		$this->assertSame( 'success', $this->orders[ $order->get_id() ]['fulfillments'][0]['status'] );
		$this->assertSame( 1, Yotpo_Order_Sync::totals()['sent'] );
	}

	public function test_order_jobs_run_ahead_of_product_jobs() {
		$this->create_order( array( array( $this->product, 1 ) ) );
		Yotpo_Sync_Jobs::queue_product( $this->product->get_id() );

		$order_jobs   = $this->order_jobs();
		$product_jobs = $this->pending_jobs();
		$order_job    = reset( $order_jobs );
		$product_job  = reset( $product_jobs );

		if ( ! method_exists( $order_job, 'get_priority' ) ) {
			$this->markTestSkipped( 'This Action Scheduler has no priorities.' );
		}
		$this->assertLessThan( $product_job->get_priority(), $order_job->get_priority() );
	}

	public function test_other_statuses_send_nothing() {
		$this->create_order( array( array( $this->product, 1 ) ), 'processing' );
		$this->create_order( array( array( $this->product, 1 ) ), 'on-hold' );

		$this->assertSame( array(), $this->order_jobs() );
	}

	public function test_the_status_the_merchant_chose_sends_the_order() {
		$this->set_setting( 'yotpo_order_status', 'wc-processing' );

		$processing = $this->create_order( array( array( $this->product, 1 ) ), 'processing' );
		$this->create_order( array( array( $this->product, 1 ) ), 'completed' );

		$jobs = $this->order_jobs( Yotpo_Order_Sync::HOOK_FULFILLED );
		$this->assertCount( 1, $jobs );
		$this->assertSame( $processing->get_id(), reset( $jobs )->get_args()[0] );
	}

	public function test_cancelling_a_sent_order_sends_the_cancellation() {
		$order = $this->create_order( array( array( $this->product, 1 ) ) );
		$this->run_order_jobs();
		$this->requests = array();

		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'cancelled' );
		$order->save();
		$this->run_order_jobs();

		$this->assertSame( array( 'PATCH /orders/' . $this->orders[ $order->get_id() ]['yotpo_id'] ), $this->calls() );
		$this->assertArrayHasKey( 'cancellation', $this->orders[ $order->get_id() ] );
	}

	public function test_a_refund_queues_an_update() {
		$order = $this->create_order( array( array( $this->product, 3 ) ) );
		$this->run_order_jobs();

		$this->refund( $order, array( array_keys( $order->get_items() )[0] => 1 ) );

		$this->assertCount( 1, $this->order_jobs( Yotpo_Order_Sync::HOOK_UPDATE ) );
		$this->run_order_jobs();
		$this->assertSame( 'partially_refunded', $this->orders[ $order->get_id() ]['payment_status'] );
	}

	public function test_switch_off_queues_nothing() {
		$this->set_setting( 'order_sync_enabled', false );

		$this->create_order( array( array( $this->product, 1 ) ) );

		$this->assertSame( array(), $this->order_jobs() );
		$this->assertSame( 'Off. Orders are not sent to Yotpo.', Yotpo_Order_Sync::status_summary() );
	}

	public function test_turning_the_switch_off_cancels_queued_jobs() {
		$this->create_order( array( array( $this->product, 1 ) ) );
		$this->assertNotEmpty( $this->order_jobs() );

		$this->set_setting( 'order_sync_enabled', false );
		Yotpo_Order_Sync::setting_changed( true, false );

		$this->assertSame( array(), $this->order_jobs() );
	}

	public function test_a_job_queued_before_the_switch_went_off_sends_nothing() {
		$this->create_order( array( array( $this->product, 1 ) ) );
		$this->set_setting( 'order_sync_enabled', false );

		$this->run_order_jobs();

		$this->assertSame( array(), $this->calls() );
	}

	public function test_turning_the_switch_on_sends_nothing_by_itself() {
		Yotpo_Order_Sync::setting_changed( false, true );

		$this->assertSame( array(), $this->order_jobs() );
		$this->assertEqualsWithDelta( time(), Yotpo_Order_Sync::stats()['enabled_at'], 5, 'Kept for the daily sweep' );
	}

	public function test_filter_turns_it_off() {
		add_filter( 'wc_yotpo_order_sync_enabled', '__return_false' );

		$this->create_order( array( array( $this->product, 1 ) ) );

		$this->assertSame( array(), $this->order_jobs() );
		$this->assertSame( 'Turned off by the wc_yotpo_order_sync_enabled filter.', Yotpo_Order_Sync::status_summary() );
		remove_filter( 'wc_yotpo_order_sync_enabled', '__return_false' );
	}

	public function test_nothing_without_keys() {
		$this->set_setting( 'secret', '' );

		$this->create_order( array( array( $this->product, 1 ) ) );

		$this->assertSame( array(), $this->order_jobs() );
		$this->assertSame( 'Starts once the app key and secret token are saved.', Yotpo_Order_Sync::status_summary() );
	}

	public function test_a_server_error_is_retried_then_counted_as_failed() {
		$order              = $this->create_order( array( array( $this->product, 1 ) ) );
		$this->create_fails = 500;

		for ( $attempt = 1; $attempt <= Yotpo_Sync_Jobs::MAX_ATTEMPTS; $attempt++ ) {
			$jobs = $this->order_jobs( Yotpo_Order_Sync::HOOK_FULFILLED );
			$this->assertCount( 1, $jobs, "Attempt $attempt is queued" );
			$this->assertSame( $attempt, reset( $jobs )->get_args()[2] );
			$this->run_job( key( $jobs ) );
		}

		$this->assertSame( array(), $this->order_jobs(), 'No attempt after the last' );
		$this->assertSame( array( 'sent' => 0, 'failed' => 1 ), Yotpo_Order_Sync::totals() );
		$this->assertStringContainsString( 'Last failure: order #' . $order->get_order_number() . ', ', Yotpo_Order_Sync::status_summary() );
		$this->assertSame( 'warning', Yotpo_Order_Sync::status_tone() );
	}

	public function test_an_order_sent_later_is_no_longer_a_failure() {
		$order              = $this->create_order( array( array( $this->product, 1 ) ) );
		$this->create_fails = 400;
		$this->run_order_jobs();
		$this->assertCount( 1, Yotpo_Order_Sync::stats()['failures'] );

		$this->create_fails = null;
		Yotpo_Order_Sync::queue_fulfilled( $order->get_id(), time() );
		$this->run_order_jobs();

		$this->assertSame( array(), Yotpo_Order_Sync::stats()['failures'] );
	}

	public function test_a_rejected_order_is_not_retried() {
		$this->create_order( array( array( $this->product, 1 ) ) );
		$this->create_fails = 400;

		$this->run_order_jobs();

		$this->assertSame( 1, count( array_keys( $this->calls(), 'POST /orders', true ) ), 'One create, no retry' );
		$this->assertSame( 1, Yotpo_Order_Sync::totals()['failed'] );
	}

	public function test_a_skipped_order_is_neither_sent_nor_failed() {
		$this->create_order( array( array( $this->product, 1 ) ), 'completed', array( 'first_name' => '' ) );

		$this->run_order_jobs();

		$this->assertNotContains( 'POST /orders', $this->calls() );
		$this->assertSame( array( 'sent' => 0, 'failed' => 0 ), Yotpo_Order_Sync::totals() );
	}

	public function test_a_429_pauses_order_and_product_jobs_alike() {
		$this->create_order( array( array( $this->product, 1 ) ) );
		$this->create_fails = 429;

		$this->run_order_jobs();

		$this->assertGreaterThan( 0, Yotpo_Sync_Jobs::paused_for(), 'The pause the product jobs also wait for' );
		$jobs = $this->order_jobs();
		$this->assertSame( 2, reset( $jobs )->get_args()[2], 'Retried after the pause' );
	}

	public function test_a_paused_job_waits_without_calling_or_spending_an_attempt() {
		$this->create_order( array( array( $this->product, 1 ) ) );
		set_transient( Yotpo_Sync_Jobs::PAUSE_TRANSIENT, time() + 60, 60 );

		$jobs = $this->order_jobs();
		$this->run_job( key( $jobs ) );

		$this->assertSame( array(), $this->calls() );
		$jobs = $this->order_jobs();
		$job  = reset( $jobs );
		$this->assertSame( 1, $job->get_args()[2] );
		$this->assertGreaterThan( time(), $job->get_schedule()->get_date()->getTimestamp() );
	}

	public function test_status_line() {
		$this->assertSame( 'On. No orders sent yet.', Yotpo_Order_Sync::status_summary() );
		$this->assertSame( 'info', Yotpo_Order_Sync::status_tone() );

		$this->create_order( array( array( $this->product, 1 ) ) );
		$this->create_order( array( array( $this->product, 1 ) ) );
		$this->run_order_jobs();

		$this->assertMatchesRegularExpression( '/^Last order sent: [^.]+\. 2 sent in the last 30 days, 0 failed\.$/', Yotpo_Order_Sync::status_summary() );
		$this->assertSame( 'success', Yotpo_Order_Sync::status_tone() );
	}

	public function test_counts_older_than_30_days_drop_out() {
		update_option(
			Yotpo_Order_Sync::STATS_OPTION,
			array(
				'last_sent' => time() - 40 * DAY_IN_SECONDS,
				'days'      => array( gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS ) => array( 'sent' => 9, 'failed' => 3 ) ),
				'failures'  => array( array( 'order_id' => 1, 'number' => '1', 'error' => 'old', 'time' => time() - 40 * DAY_IN_SECONDS ) ),
			)
		);

		$this->assertSame( array( 'sent' => 0, 'failed' => 0 ), Yotpo_Order_Sync::totals() );

		$this->create_order( array( array( $this->product, 1 ) ) );
		$this->run_order_jobs();
		$stats = Yotpo_Order_Sync::stats();
		$this->assertCount( 1, $stats['days'], 'Old days are pruned on the next save' );
		$this->assertSame( array(), $stats['failures'] );
	}

	// -----------------------------------------------------------------------------------------

	private function order_jobs( $hook = null ) {
		return $this->pending_jobs( $hook, Yotpo_Order_Sync::GROUP );
	}

	private function run_job( $action_id ) {
		ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'phpunit' );
	}

	/** Runs the queued order jobs that are due, including any they queue for now. */
	private function run_order_jobs() {
		for ( $round = 0; $round < 10; $round++ ) {
			$due = array_filter(
				$this->order_jobs(),
				function ( $job ) {
					$date = $job->get_schedule()->get_date(); // None for an async job: due now.
					return ! $date || $date->getTimestamp() <= time();
				}
			);
			if ( ! $due ) {
				return;
			}
			foreach ( array_keys( $due ) as $action_id ) {
				$this->run_job( $action_id );
			}
		}
	}

	private function route_fake_orders() {
		$this->route( 'GET', '#^/orders$#', function ( $request ) {
			parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
			$found = array();
			foreach ( explode( ',', $query['external_ids'] ) as $id ) {
				if ( isset( $this->orders[ $id ] ) ) {
					$found[] = array( 'yotpo_id' => $this->orders[ $id ]['yotpo_id'], 'external_id' => (string) $id );
				}
			}
			return $this->respond( 200, array( 'orders' => $found ) );
		} );
		$this->route( 'POST', '#^/orders$#', function ( $request ) {
			if ( $this->create_fails ) {
				return $this->respond( $this->create_fails, null, 429 === $this->create_fails ? array( 'retry-after' => '30' ) : array() );
			}
			$order                                   = $request['body']['order'];
			$this->orders[ $order['external_id'] ] = array_merge( $order, array( 'yotpo_id' => ++$this->next_id ) );
			return $this->respond( 201, array( 'order' => array( 'yotpo_id' => $this->next_id ) ) );
		} );
		$this->route( 'PATCH', '#^/orders/\d+$#', function ( $request ) {
			$yotpo_id = (int) substr( $request['path'], strlen( '/orders/' ) );
			foreach ( $this->orders as $id => $order ) {
				if ( $order['yotpo_id'] === $yotpo_id ) {
					$this->orders[ $id ] = array_merge( $order, $request['body']['order'] );
					return $this->respond( 200 );
				}
			}
			return $this->respond( 404 );
		} );
	}
}
