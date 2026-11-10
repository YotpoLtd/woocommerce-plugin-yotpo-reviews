<?php
/**
 * The warning that background jobs aren't running: on a site where WP-Cron doesn't run, Action Scheduler
 * only runs jobs while someone is in wp-admin, and the product and order sync look frozen.
 */
class LateJobsTest extends Yotpo_Test_Case {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/trunk/templates/wc-yotpo-settings.php';
	}

	public function test_no_warning_without_jobs() {
		$this->assertSame( 0, Yotpo_Sync_Jobs::late_since( Yotpo_Sync_Jobs::GROUP ) );
		$this->assertSame( '', Yotpo_Sync_Jobs::late_warning( Yotpo_Sync_Jobs::GROUP ) );
	}

	public function test_no_warning_for_jobs_queued_just_now_or_due_later() {
		as_enqueue_async_action( Yotpo_Sync_Jobs::HOOK_BATCH, array( 1 ), Yotpo_Sync_Jobs::GROUP );
		as_schedule_single_action( time() + 300, Yotpo_Sync_Jobs::HOOK_PRODUCT, array( 1, 2, 0 ), Yotpo_Sync_Jobs::GROUP ); // A retry.
		as_schedule_single_action( time() - 600, Yotpo_Sync_Jobs::HOOK_PRODUCT, array( 2, 1, 0 ), Yotpo_Sync_Jobs::GROUP ); // Ten minutes late: a busy queue.

		$this->assertSame( 0, Yotpo_Sync_Jobs::late_since( Yotpo_Sync_Jobs::GROUP ) );
	}

	public function test_a_job_overdue_for_more_than_an_hour_gives_the_oldest_due_time() {
		$oldest = time() - 3 * HOUR_IN_SECONDS;
		as_schedule_single_action( time() - 2 * HOUR_IN_SECONDS, Yotpo_Sync_Jobs::HOOK_PRODUCT, array( 1, 1, 0 ), Yotpo_Sync_Jobs::GROUP );
		as_schedule_single_action( $oldest, Yotpo_Sync_Jobs::HOOK_BATCH, array( 8 ), Yotpo_Sync_Jobs::GROUP );

		$this->assertSame( $oldest, Yotpo_Sync_Jobs::late_since( Yotpo_Sync_Jobs::GROUP ) );
		$this->assertSame(
			'A Yotpo background job due on ' . wp_date( 'j M Y, H:i', $oldest ) . " is still waiting. That is normal while a large backlog catches up; if it doesn't clear, background jobs may not be running on this site, so ask your host to check that WP-Cron runs. WooCommerce > Status > Scheduled Actions lists the waiting jobs.",
			Yotpo_Sync_Jobs::late_warning( Yotpo_Sync_Jobs::GROUP )
		);
	}

	public function test_each_group_is_checked_on_its_own() {
		as_schedule_single_action( time() - 2 * HOUR_IN_SECONDS, Yotpo_Order_Sync::HOOK_FULFILLED, array( 1, time(), 1 ), Yotpo_Order_Sync::GROUP );

		$this->assertSame( 0, Yotpo_Sync_Jobs::late_since( Yotpo_Sync_Jobs::GROUP ) );
		$this->assertGreaterThan( 0, Yotpo_Sync_Jobs::late_since( Yotpo_Order_Sync::GROUP ) );
	}

	public function test_the_warning_shows_under_the_card_whose_jobs_are_late() {
		as_schedule_single_action( time() - 2 * HOUR_IN_SECONDS, Yotpo_Order_Sync::HOOK_FULFILLED, array( 1, time(), 1 ), Yotpo_Order_Sync::GROUP );

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'yotpo-late-jobs' ) );
		$orders = substr( $html, strpos( $html, '>Orders<' ) );
		$orders = substr( $orders, 0, strpos( $orders, '</section>' ) );
		$this->assertStringContainsString( '<div class="yotpo-status yotpo-tone--warning yotpo-late-jobs">A Yotpo background job due on', $orders );
	}

	public function test_nothing_shows_while_jobs_run_on_time() {
		as_enqueue_async_action( Yotpo_Sync_Jobs::HOOK_BATCH, array( 1 ), Yotpo_Sync_Jobs::GROUP );

		$this->assertStringNotContainsString( 'yotpo-late-jobs', $this->render() );
	}

	private function render() {
		ob_start();
		wc_display_yotpo_settings();
		return wp_kses( ob_get_clean(), yotpo_settings_allowed_html() );
	}
}
