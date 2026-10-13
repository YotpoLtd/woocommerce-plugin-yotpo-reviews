<?php

defined( 'ABSPATH' ) || exit;

/**
 * The background side of the product sync, on Action Scheduler (ships with WooCommerce):
 * WooCommerce hooks that queue jobs, the job runners, retries and the pause after a 429.
 * No API work ever runs inside a page load.
 */
class Yotpo_Sync_Jobs {

	// GROUP is also written out in uninstall.php, which runs without this class loaded: keep both in sync.
	const GROUP                = 'yotpo-product-sync';
	const BATCH_SIZE           = 20;
	const MAX_ATTEMPTS         = 3;
	const RETRY_BASE_DELAY     = 60; // Seconds before the first retry; doubles with each attempt.
	const RATE_LIMIT_MIN_DELAY = 5; // Seconds to wait at least after a 429, even if Retry-After is lower or missing.
	const PAUSE_TRANSIENT      = 'wc_yotpo_product_sync_paused_until'; // Set after a 429: until then no job calls Yotpo.
	const STALL_AFTER          = 3600; // Seconds without progress after which a running sync with no jobs left counts as dead.
	const MAX_STALL_RESTARTS   = 3; // Automatic restarts of a stalled full sync before it is left stalled for the merchant to retry.
	const RECHECK_TRANSIENT    = 'wc_yotpo_product_sync_recheck'; // Throttles the queue checks of maybe_start_initial_sync().
	const RECHECK_EVERY        = 300; // Seconds between those checks while a sync is stopped or possibly stalled.

	const HOOK_PRODUCT      = 'wc_yotpo_sync_product';
	const HOOK_BATCH        = 'wc_yotpo_sync_product_batch';
	const HOOK_VARIANT_GONE = 'wc_yotpo_discontinue_variant';

	public static function register() {
		add_action( self::HOOK_PRODUCT, array( __CLASS__, 'run_product_job' ), 10, 3 );
		add_action( self::HOOK_BATCH, array( __CLASS__, 'run_batch_job' ), 10, 1 );
		add_action( self::HOOK_VARIANT_GONE, array( __CLASS__, 'run_variant_gone_job' ), 10, 3 );

		// The kill switch is checked where jobs are queued and run, not here: a filter added after
		// plugins_loaded (theme, mu-plugin) must still take effect.
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'queue_variation_parent' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_variation_parent' ) );
		add_action( 'trashed_post', array( __CLASS__, 'queue_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'queue_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_deleted_post' ) );
		add_action( 'init', array( __CLASS__, 'maybe_start_initial_sync' ), 20 );
	}

	// ---------------------------------------------------------------------------------------
	// Full sync
	// ---------------------------------------------------------------------------------------

	/**
	 * Runs on every request (init). Starts the full catalog sync when it never ran for this app key
	 * (after updating to this version, or after the merchant connects a different Yotpo account),
	 * restarts one stopped by the off switch, and restarts one that stalled (a job died without
	 * counting itself). The usual case, a finished sync for this account, costs no query; the
	 * queue checks for the other cases run at most once every RECHECK_EVERY seconds.
	 */
	public static function maybe_start_initial_sync() {
		if ( ! self::scheduler_ready() || ! Yotpo_Product_Sync::enabled() ) {
			return;
		}
		$client = Yotpo_V3_Client::from_settings();
		if ( ! $client ) {
			return;
		}
		$state  = Yotpo_Sync_State::get();
		$status = isset( $state['status'] ) ? $state['status'] : '';
		if ( ! isset( $state['app_key'] ) || $state['app_key'] !== $client->app_key() ) {
			self::start_full_sync();
			return;
		}
		$last_progress  = max( isset( $state['last_activity'] ) ? (int) $state['last_activity'] : 0, isset( $state['started'] ) ? (int) $state['started'] : 0 );
		$was_stopped    = Yotpo_Sync_State::STATUS_STOPPED === $status;
		$may_be_stalled = Yotpo_Sync_State::STATUS_RUNNING === $status && time() - $last_progress > self::STALL_AFTER;
		if ( ! $was_stopped && ! $may_be_stalled ) {
			return;
		}
		if ( get_transient( self::RECHECK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::RECHECK_TRANSIENT, 1, self::RECHECK_EVERY );
		if ( self::jobs_left() ) {
			return; // Still draining (stopped) or still working (not stalled after all).
		}
		if ( $was_stopped ) {
			self::start_full_sync();
			return;
		}
		// Stalled: restart a few times, then leave it for the merchant (a product whose job keeps dying
		// would otherwise re-send the whole catalog every hour).
		$restarts = isset( $state['stall_restarts'] ) ? (int) $state['stall_restarts'] : 0;
		if ( $restarts >= self::MAX_STALL_RESTARTS ) {
			Yotpo_Sync_State::mark_stalled( $restarts );
			ytdbg( $restarts . ' restarts', 'Product sync: full sync left stalled after' );
			return;
		}
		ytdbg( 'restart ' . ( $restarts + 1 ) . ' of ' . self::MAX_STALL_RESTARTS, 'Product sync: full sync stalled,' );
		self::start_full_sync( $restarts + 1 );
	}

	/**
	 * @param int $stall_restarts How many times a stalled sync was restarted automatically (0 for a normal start).
	 * @return true|WP_Error
	 */
	public static function start_full_sync( $stall_restarts = 0 ) {
		$client = Yotpo_V3_Client::from_settings();
		if ( ! $client ) {
			return new WP_Error( 'yotpo_no_credentials', 'Save the Yotpo app key and secret first.' );
		}
		if ( ! self::scheduler_ready() ) {
			return new WP_Error( 'yotpo_no_scheduler', 'Action Scheduler is not available.' );
		}
		if ( ! Yotpo_Product_Sync::enabled() ) {
			return new WP_Error( 'yotpo_sync_disabled', 'Product sync is turned off (Yotpo settings, or the wc_yotpo_product_sync_enabled filter).' );
		}
		// Restarting while jobs are queued or running would reset the counters under them and could finish the
		// new sync early. That includes a sync stopped by the off switch: its jobs stay queued until they run.
		$current = Yotpo_Sync_State::get();
		if ( isset( $current['status'] ) && in_array( $current['status'], array( Yotpo_Sync_State::STATUS_RUNNING, Yotpo_Sync_State::STATUS_STOPPED ), true ) && self::jobs_left() ) {
			return new WP_Error( 'yotpo_sync_busy', 'A full product sync still has jobs queued or running. Try again when they have finished.' );
		}
		Yotpo_Sync_State::start( $client->app_key(), $stall_restarts );
		as_enqueue_async_action( self::HOOK_BATCH, array( 1 ), self::GROUP, true );
		ytdbg( '', 'Product sync: full sync queued' );
		return true;
	}

	// ---------------------------------------------------------------------------------------
	// Queueing
	// ---------------------------------------------------------------------------------------

	/**
	 * @param bool $full_sync True for a job of the full catalog sync: only those are counted in the sync state.
	 * @return int The queued action ID (0 when nothing was queued, for example a duplicate).
	 */
	public static function queue_product( $product_id, $attempt = 1, $delay = 0, $full_sync = false ) {
		if ( ! self::scheduler_ready() || ! Yotpo_Product_Sync::enabled() ) {
			return 0;
		}
		$args = array( (int) $product_id, (int) $attempt );
		if ( $full_sync ) {
			$args[] = 1;
		}
		if ( $delay > 0 ) {
			return (int) as_schedule_single_action( time() + $delay, self::HOOK_PRODUCT, $args, self::GROUP, true );
		}
		return (int) as_enqueue_async_action( self::HOOK_PRODUCT, $args, self::GROUP, true );
	}

	/**
	 * Queues the job that marks a deleted variation as discontinued in Yotpo.
	 *
	 * @return int The queued action ID (0 when nothing was queued, for example a duplicate).
	 */
	public static function queue_variant_gone( $variation_id, $parent_id, $attempt = 1, $delay = 0 ) {
		if ( ! self::scheduler_ready() || ! Yotpo_Product_Sync::enabled() ) {
			return 0;
		}
		$args = array( (int) $variation_id, (int) $parent_id, (int) $attempt );
		if ( $delay > 0 ) {
			return (int) as_schedule_single_action( time() + $delay, self::HOOK_VARIANT_GONE, $args, self::GROUP, true );
		}
		return (int) as_enqueue_async_action( self::HOOK_VARIANT_GONE, $args, self::GROUP, true );
	}

	public static function queue_variation_parent( $variation_id ) {
		$parent_id = wp_get_post_parent_id( $variation_id );
		if ( $parent_id ) {
			self::queue_product( $parent_id );
		}
	}

	public static function queue_post( $post_id ) {
		$type = get_post_type( $post_id );
		if ( 'product' === $type ) {
			self::queue_product( $post_id );
		} elseif ( 'product_variation' === $type ) {
			self::queue_variation_parent( $post_id );
		}
	}

	/**
	 * Runs before a post is deleted for good, while its type and parent are still readable.
	 */
	public static function queue_deleted_post( $post_id ) {
		if ( ! self::scheduler_ready() ) {
			return;
		}
		$type = get_post_type( $post_id );
		if ( 'product' === $type ) {
			// The product job finds no product, looks it up in Yotpo by external ID and blocklists it.
			self::queue_product( $post_id );
		} elseif ( 'product_variation' === $type ) {
			$parent_id = wp_get_post_parent_id( $post_id );
			if ( $parent_id ) {
				self::queue_variant_gone( $post_id, $parent_id );
			}
		}
	}

	// ---------------------------------------------------------------------------------------
	// Jobs
	// ---------------------------------------------------------------------------------------

	/**
	 * One page of the full sync: queues one job per published product (so each action stays short
	 * and gets its own retries), then queues the next page. The sync is complete once every queued
	 * product job has finished (see Yotpo_Sync_State::count_result()).
	 */
	public static function run_batch_job( $page ) {
		// The off switch also stops jobs that were queued before it was turned on.
		if ( ! Yotpo_Product_Sync::enabled() ) {
			Yotpo_Sync_State::stop();
			return;
		}
		$page = max( 1, (int) $page );
		$ids  = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => self::BATCH_SIZE,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);
		$queued = 0;
		foreach ( $ids as $product_id ) {
			if ( self::queue_product( $product_id, 1, 0, true ) ) {
				++$queued;
			}
		}
		$more = count( $ids ) === self::BATCH_SIZE;
		if ( $more ) {
			as_enqueue_async_action( self::HOOK_BATCH, array( $page + 1 ), self::GROUP, true );
		}
		Yotpo_Sync_State::page_queued( $queued, $more );
	}

	/**
	 * One product, queued by a change in WooCommerce or by the full sync. Retries with growing delays.
	 * Only the full sync's jobs count towards its progress.
	 */
	public static function run_product_job( $product_id, $attempt = 1, $full_sync = 0 ) {
		if ( ! Yotpo_Product_Sync::enabled() ) {
			if ( $full_sync ) {
				Yotpo_Sync_State::stop();
			}
			return;
		}
		// Yotpo asked every caller to slow down: wait without spending an attempt.
		$paused_for = self::paused_for();
		if ( $paused_for > 0 ) {
			self::queue_product( $product_id, $attempt, $paused_for, (bool) $full_sync );
			return;
		}
		// The full sync forces a resend on the first attempt only; a retry resumes from the stored
		// hashes, so it does not send again what Yotpo already accepted.
		$result = Yotpo_Product_Sync::sync_product( $product_id, $full_sync && 1 === (int) $attempt );
		self::pause_if_rate_limited( $result, $attempt );
		if ( is_wp_error( $result ) && Yotpo_Catalog_Api::is_retryable( $result ) && $attempt < self::MAX_ATTEMPTS ) {
			self::queue_product( $product_id, $attempt + 1, self::retry_delay( $result, $attempt ), (bool) $full_sync );
			return;
		}
		if ( $full_sync ) {
			Yotpo_Sync_State::count_result( $result );
		}
	}

	public static function run_variant_gone_job( $variation_id, $parent_id, $attempt = 1 ) {
		if ( ! Yotpo_Product_Sync::enabled() ) {
			return;
		}
		$client = Yotpo_V3_Client::from_settings();
		if ( ! $client ) {
			return;
		}
		$paused_for = self::paused_for();
		if ( $paused_for > 0 ) {
			self::queue_variant_gone( $variation_id, $parent_id, $attempt, $paused_for );
			return;
		}
		$error = Yotpo_Catalog_Api::discontinue_variant( $client, $variation_id, $parent_id );
		self::pause_if_rate_limited( $error, $attempt );
		if ( ! $error ) {
			return;
		}
		if ( Yotpo_Catalog_Api::is_retryable( $error ) && $attempt < self::MAX_ATTEMPTS ) {
			self::queue_variant_gone( $variation_id, $parent_id, $attempt + 1, self::retry_delay( $error, $attempt ) );
			return;
		}
		ytdbg( $error->get_error_message(), 'Product sync: variation #' . $variation_id . ' not discontinued:' );
	}

	// ---------------------------------------------------------------------------------------
	// Retry policy
	// ---------------------------------------------------------------------------------------

	/**
	 * Seconds to wait before retrying after a retryable error: Retry-After for a 429,
	 * else a delay that doubles with each attempt.
	 */
	private static function retry_delay( $error, $attempt ) {
		if ( 'yotpo_rate_limited' === $error->get_error_code() ) {
			return max( self::RATE_LIMIT_MIN_DELAY, (int) $error->get_error_data() );
		}
		return self::RETRY_BASE_DELAY * ( 2 ** $attempt );
	}

	/**
	 * Seconds left of the pause that a 429 set for all sync jobs (0 when there is none).
	 */
	private static function paused_for() {
		return max( 0, (int) get_transient( self::PAUSE_TRANSIENT ) - time() );
	}

	/**
	 * A 429 answers for the whole app key, so it pauses every queued job, not only the one that got it.
	 *
	 * @param mixed $result A job result; anything but a rate-limit error is ignored.
	 */
	private static function pause_if_rate_limited( $result, $attempt ) {
		if ( is_wp_error( $result ) && 'yotpo_rate_limited' === $result->get_error_code() ) {
			$seconds = self::retry_delay( $result, $attempt );
			set_transient( self::PAUSE_TRANSIENT, time() + $seconds, $seconds );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Queue
	// ---------------------------------------------------------------------------------------

	/**
	 * Sync jobs waiting to run and running now.
	 *
	 * @return array{queued:int, in_progress:int}
	 */
	public static function queue_counts() {
		$counts = array( 'queued' => 0, 'in_progress' => 0 );
		if ( self::scheduler_ready() && class_exists( 'ActionScheduler' ) ) {
			$counts['queued']      = (int) ActionScheduler::store()->query_actions(
				array(
					'group'  => self::GROUP,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				),
				'count'
			);
			$counts['in_progress'] = (int) ActionScheduler::store()->query_actions(
				array(
					'group'  => self::GROUP,
					'status' => ActionScheduler_Store::STATUS_RUNNING,
				),
				'count'
			);
		}
		return $counts;
	}

	/**
	 * Whether sync jobs are still queued or running.
	 */
	private static function jobs_left() {
		$counts = self::queue_counts();
		return $counts['queued'] + $counts['in_progress'] > 0;
	}

	/**
	 * Cancels every queued sync job (used when the merchant turns the sync off).
	 */
	public static function cancel_all() {
		if ( self::scheduler_ready() ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	private static function scheduler_ready() {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' );
	}
}
