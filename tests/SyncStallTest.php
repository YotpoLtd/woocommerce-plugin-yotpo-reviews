<?php
/**
 * A full sync that stops making progress: automatic restarts (at most MAX_STALL_RESTARTS), the
 * throttle on the checks page loads run, and the "jobs still queued or running" guard.
 */
class SyncStallTest extends Yotpo_Test_Case {

	public function test_finished_sync_costs_page_loads_nothing() {
		$this->set_state( array( 'status' => Yotpo_Sync_State::STATUS_COMPLETE, 'finished' => time() ) );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();

		$this->assertSame( array(), $this->pending_jobs() );
		$this->assertFalse( get_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT ), 'Not even a queue check' );
	}

	public function test_stalled_sync_is_restarted_and_counted() {
		$this->set_state( array() );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();

		$state = Yotpo_Product_Sync::state();
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ) );
		$this->assertSame( Yotpo_Sync_State::STATUS_RUNNING, $state['status'] );
		$this->assertSame( 1, $state['stall_restarts'] );
		$this->assertSame( 0, $state['synced'], 'Counters start again' );
		$this->assertStringContainsString( 'stalled', $state['last_error'] );
		$this->assertNotFalse( get_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT ) );
	}

	public function test_checks_are_throttled() {
		set_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT, 1, Yotpo_Sync_Jobs::RECHECK_EVERY );
		$this->set_state( array( 'stall_restarts' => 1 ) );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();

		$this->assertSame( array(), $this->pending_jobs() );
		$this->assertSame( 1, Yotpo_Product_Sync::state()['stall_restarts'] );
	}

	public function test_sync_with_a_running_job_is_not_restarted() {
		$this->set_state( array( 'stall_restarts' => 1 ) );
		$action_id = as_enqueue_async_action( Yotpo_Sync_Jobs::HOOK_PRODUCT, array( 999999, 1, true ), Yotpo_Sync_Jobs::GROUP );
		ActionScheduler::store()->log_execution( $action_id ); // In progress, as while a runner works on it.
		$this->assertSame( array( 'queued' => 0, 'in_progress' => 1 ), Yotpo_Sync_Jobs::queue_counts() );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();
		$this->assertSame( 1, Yotpo_Product_Sync::state()['stall_restarts'], 'Not restarted' );
		$this->assertSame( array(), $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ) );

		$busy = Yotpo_Sync_Jobs::start_full_sync();
		$this->assertWPError( $busy );
		$this->assertSame( 'yotpo_sync_busy', $busy->get_error_code(), 'A manual start is refused too' );
	}

	public function test_after_the_last_restart_it_is_left_stalled() {
		$this->set_state( array( 'stall_restarts' => Yotpo_Sync_Jobs::MAX_STALL_RESTARTS ) );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();

		$state = Yotpo_Product_Sync::state();
		$this->assertSame( Yotpo_Sync_State::STATUS_STALLED, $state['status'] );
		$this->assertGreaterThan( 0, $state['finished'] );
		$this->assertSame( array(), $this->pending_jobs() );
		$this->assertStringStartsWith( 'Full sync stalled: 3 of 12 products done. The full sync stalled 3 times', Yotpo_Product_Sync::status_summary() );

		delete_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT );
		Yotpo_Sync_Jobs::maybe_start_initial_sync();
		$this->assertSame( Yotpo_Sync_State::STATUS_STALLED, Yotpo_Product_Sync::state()['status'], 'Later page loads leave it alone' );
		$this->assertFalse( get_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT ) );
	}

	public function test_turning_the_setting_off_and_on_retries_a_stalled_sync_from_zero() {
		$this->set_state( array( 'status' => Yotpo_Sync_State::STATUS_STALLED, 'stall_restarts' => 3 ) );

		Yotpo_Product_Sync::setting_changed( true, false );
		$this->assertSame( Yotpo_Sync_State::STATUS_STALLED, Yotpo_Product_Sync::state()['status'], 'Off leaves it stalled' );
		Yotpo_Product_Sync::setting_changed( false, true );

		$state = Yotpo_Product_Sync::state();
		$this->assertSame( Yotpo_Sync_State::STATUS_RUNNING, $state['status'] );
		$this->assertSame( 0, $state['stall_restarts'] );
		$this->assertSame( '', $state['last_error'] );
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ) );
	}

	public function test_stopped_sync_restarts_once_its_jobs_are_gone() {
		$this->set_state( array( 'status' => Yotpo_Sync_State::STATUS_STOPPED, 'last_activity' => time() ) );
		as_enqueue_async_action( Yotpo_Sync_Jobs::HOOK_PRODUCT, array( 999998, 1, true ), Yotpo_Sync_Jobs::GROUP );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();
		$this->assertSame( Yotpo_Sync_State::STATUS_STOPPED, Yotpo_Product_Sync::state()['status'], 'Waits while a job is queued' );

		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );
		Yotpo_Sync_Jobs::maybe_start_initial_sync();
		$this->assertSame( Yotpo_Sync_State::STATUS_STOPPED, Yotpo_Product_Sync::state()['status'], 'And for the throttle' );

		delete_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT );
		Yotpo_Sync_Jobs::maybe_start_initial_sync();
		$this->assertSame( Yotpo_Sync_State::STATUS_RUNNING, Yotpo_Product_Sync::state()['status'] );
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ) );
	}

	public function test_new_app_key_starts_a_sync_at_once() {
		set_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT, 1, Yotpo_Sync_Jobs::RECHECK_EVERY );
		$this->set_state( array( 'status' => Yotpo_Sync_State::STATUS_COMPLETE, 'app_key' => 'some-other-key' ) );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();

		$this->assertSame( Yotpo_Sync_State::STATUS_RUNNING, Yotpo_Product_Sync::state()['status'] );
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ) );
	}

	/** A running full sync for this app key with no progress for two hours, changed by $changes. */
	private function set_state( $changes ) {
		update_option(
			Yotpo_Sync_State::STATE_OPTION,
			array_merge(
				array(
					'app_key'       => self::APP_KEY,
					'status'        => Yotpo_Sync_State::STATUS_RUNNING,
					'started'       => time() - 7200,
					'last_activity' => time() - 7200,
					'synced'        => 3,
					'failed'        => 0,
					'skipped'       => 0,
					'total'         => 12,
					'pages_done'    => true,
				),
				$changes
			)
		);
	}
}
