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
	const BATCH_SIZE           = Yotpo_Catalog_Api::LOOKUP_MAX; // Products per full-sync page: one lookup request compares them all with Yotpo, so never more than it holds.
	const MAX_ATTEMPTS         = 3;
	const RETRY_BASE_DELAY     = 60; // Seconds before the first retry; doubles with each attempt.
	const RATE_LIMIT_MIN_DELAY = 5; // Seconds to wait at least after a 429, even if Retry-After is lower or missing.
	const PAUSE_TRANSIENT      = 'wc_yotpo_product_sync_paused_until'; // Set after a 429: until then no job calls Yotpo.
	const STALL_AFTER          = 3600; // Seconds without progress after which a running sync with no jobs left counts as dead.
	const MAX_STALL_RESTARTS   = 3; // Automatic restarts of a stalled full sync before it is left stalled for the merchant to retry.
	const RECHECK_TRANSIENT    = 'wc_yotpo_product_sync_recheck'; // Throttles the queue checks of maybe_start_initial_sync().
	const RECHECK_EVERY        = 300; // Seconds between those checks while a sync is stopped or possibly stalled.

	// The third argument of a product job: 0 for a job of a product save, else the stage of a full-sync job.
	const FULL_SYNC          = 1; // The variations are still to be compared with Yotpo.
	const FULL_SYNC_COMPARED = 2; // They were; a requeue or a retry doesn't compare again.

	const HOOK_PRODUCT      = 'wc_yotpo_sync_product';
	const HOOK_BATCH        = 'wc_yotpo_sync_product_batch';
	const HOOK_VARIANT_GONE = 'wc_yotpo_discontinue_variant';
	const HOOK_TAGGED       = 'wc_yotpo_sync_tagged_products';

	public static function register() {
		add_action( self::HOOK_PRODUCT, array( __CLASS__, 'run_product_job' ), 10, 3 );
		add_action( self::HOOK_BATCH, array( __CLASS__, 'run_batch_job' ), 10, 1 );
		add_action( self::HOOK_VARIANT_GONE, array( __CLASS__, 'run_variant_gone_job' ), 10, 3 );
		add_action( self::HOOK_TAGGED, array( __CLASS__, 'run_tagged_products_job' ), 10, 1 );

		// The kill switch is checked where jobs are queued and run, not here: a filter added after
		// plugins_loaded (theme, mu-plugin) must still take effect.
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'queue_variation_parent' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_variation_parent' ) );
		add_action( 'trashed_post', array( __CLASS__, 'queue_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'queue_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_deleted_post' ) );
		// Y-tags (see Yotpo_Product_Tags): tags set without a product save (an import, code), and a
		// Y-tag renamed or deleted under Products > Tags, which changes every product that has it.
		// edit_terms and pre_delete_term see the tag's old name, edited_term its new one.
		add_action( 'set_object_terms', array( __CLASS__, 'queue_retagged_product' ), 10, 4 );
		add_action( 'edit_terms', array( __CLASS__, 'queue_tagged_products' ), 10, 2 );
		add_action( 'edited_term', array( __CLASS__, 'queue_renamed_tagged_products' ), 10, 3 );
		add_action( 'pre_delete_term', array( __CLASS__, 'queue_tagged_products' ), 10, 2 );
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
		if ( Yotpo_Sync_State::STATUS_COMPLETE === $status && ! empty( $state['rerun'] ) ) {
			// A full sync was asked for while this one ran (see request_full_sync()).
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

	/**
	 * Starts a full sync, or, while one is still running, has the next page load start one once it
	 * is complete. For changes that every product must pick up, such as turning the Y-tags on: a
	 * running full sync may already be past some products.
	 *
	 * @return true|WP_Error
	 */
	public static function request_full_sync() {
		$result = self::start_full_sync();
		if ( is_wp_error( $result ) && 'yotpo_sync_busy' === $result->get_error_code() ) {
			Yotpo_Sync_State::request_rerun();
			return true;
		}
		return $result;
	}

	// ---------------------------------------------------------------------------------------
	// Queueing
	// ---------------------------------------------------------------------------------------

	/**
	 * @param int|bool $full_sync 0 (or false) for a product save; FULL_SYNC or FULL_SYNC_COMPARED (true is FULL_SYNC)
	 *                            for a job of the full catalog sync: only those are counted in the sync state.
	 * @return int The queued action ID (0 when nothing was queued, for example a duplicate).
	 */
	public static function queue_product( $product_id, $attempt = 1, $delay = 0, $full_sync = 0 ) {
		if ( ! self::scheduler_ready() || ! Yotpo_Product_Sync::enabled() ) {
			return 0;
		}
		$args = array( (int) $product_id, (int) $attempt );
		if ( $full_sync ) {
			$args[] = (int) $full_sync;
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
	 * A product's tags were set (set_object_terms): with the Y-tags on, its Yotpo fields may change.
	 * Saving a product in the admin sets its tags too; the job is queued once (queue_product()).
	 */
	public static function queue_retagged_product( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'product_tag' === $taxonomy && Yotpo_Product_Sync::tags_on() && 'product' === get_post_type( $object_id ) ) {
			self::queue_product( $object_id );
		}
	}

	/**
	 * Runs on edit_terms and pre_delete_term, so it sees the tag's old name (via
	 * queue_renamed_tagged_products() it also runs on edited_term, and sees the new name): if the
	 * tag is a Y-tag, every product that has it is synced again. The jobs run afterwards, so they
	 * see the new name, or no tag. A tag that becomes a Y-tag is therefore caught after the rename,
	 * one that stops being a Y-tag before it.
	 */
	public static function queue_tagged_products( $term_id, $taxonomy ) {
		if ( 'product_tag' !== $taxonomy || ! Yotpo_Product_Sync::tags_on() ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) || ! Yotpo_Product_Tags::is_y_tag( Yotpo_Product_Terms::name( $term ) ) ) {
			return;
		}
		if ( ! self::scheduler_ready() || ! Yotpo_Product_Sync::enabled() ) {
			return;
		}
		// One job per page of products, not one per product: a Y-tag can be on thousands of products,
		// and this runs inside the admin request. The jobs queue the product jobs (run_tagged_products_job()).
		// The IDs are listed now: after a delete the tag no longer has any products.
		$product_ids = get_objects_in_term( (int) $term_id, $taxonomy );
		foreach ( array_chunk( is_array( $product_ids ) ? array_map( 'intval', $product_ids ) : array(), self::BATCH_SIZE ) as $page_ids ) {
			as_enqueue_async_action( self::HOOK_TAGGED, array( $page_ids ), self::GROUP, true );
		}
	}

	/**
	 * Queues the product job of each product of a page of a renamed or deleted Y-tag's products.
	 *
	 * @param int[] $product_ids
	 */
	public static function run_tagged_products_job( $product_ids ) {
		foreach ( (array) $product_ids as $product_id ) {
			self::queue_product( (int) $product_id );
		}
	}

	/**
	 * A product tag was renamed (edited_term): if it is a Y-tag now, every product that has it is
	 * synced again. edit_terms only passes the new name from WordPress 6.1; edited_term sees the
	 * saved name on every version. A Y-tag renamed into another Y-tag is queued twice: the unique
	 * async action in queue_tagged_products() drops the second page job, and queue_product() drops
	 * any product job that is queued already.
	 */
	public static function queue_renamed_tagged_products( $term_id, $tt_id, $taxonomy ) {
		self::queue_tagged_products( $term_id, $taxonomy );
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
	 * One page of the full sync: compares the page's published products with Yotpo (one request),
	 * queues one job per product (so each action stays short and gets its own retries), then queues
	 * the next page. The sync is complete once every queued product job has finished (see
	 * Yotpo_Sync_State::count_result()).
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
		self::compare_with_yotpo( $ids );
		$queued = 0;
		foreach ( $ids as $product_id ) {
			if ( self::queue_product( $product_id, 1, 0, self::FULL_SYNC ) ) {
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
			self::queue_product( $product_id, $attempt, $paused_for, $full_sync );
			return;
		}
		// The page job compared the product with Yotpo; its variations are compared here, in their own
		// request. Once, whatever follows (a pause, a retry): the answer is in post meta, and the job
		// resumes from what Yotpo already accepted.
		if ( self::FULL_SYNC === (int) $full_sync ) {
			self::compare_variants_with_yotpo( $product_id );
			$full_sync  = self::FULL_SYNC_COMPARED;
			// That lookup may have got a 429, which pauses every job: wait, as above, instead of sending now.
			$paused_for = self::paused_for();
			if ( $paused_for > 0 ) {
				self::queue_product( $product_id, $attempt, $paused_for, $full_sync );
				return;
			}
		}
		$result = Yotpo_Product_Sync::sync_product( $product_id );
		self::pause_if_rate_limited( $result, $attempt );
		if ( is_wp_error( $result ) && Yotpo_Catalog_Api::is_retryable( $result ) && $attempt < self::MAX_ATTEMPTS ) {
			self::queue_product( $product_id, $attempt + 1, self::retry_delay( $result, $attempt ), $full_sync );
			return;
		}
		if ( $full_sync ) {
			Yotpo_Sync_State::count_result( $result );
		}
	}

	/**
	 * Sets the stored IDs and fingerprints of a page of products to what Yotpo has (see
	 * Yotpo_Catalog_Api::compare_products()). If Yotpo can't be asked now, the products are all
	 * sent, as they would be without the comparison.
	 */
	private static function compare_with_yotpo( array $product_ids ) {
		if ( ! $product_ids ) {
			return;
		}
		self::compare_or_send_all(
			'comparing a page with Yotpo',
			function () use ( $product_ids ) {
				Yotpo_Catalog_Api::forget_product_fingerprints( $product_ids );
			},
			function ( $client ) use ( $product_ids ) {
				return Yotpo_Catalog_Api::compare_products( $client, array_filter( array_map( 'wc_get_product', $product_ids ) ) );
			}
		);
	}

	/**
	 * The same for the variations of one variable product (see Yotpo_Catalog_Api::compare_variants(),
	 * which knows which variations those are, and does nothing for any other product).
	 */
	private static function compare_variants_with_yotpo( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		self::compare_or_send_all(
			'comparing the variations of #' . $product_id . ' with Yotpo',
			function () use ( $product ) {
				Yotpo_Catalog_Api::forget_variant_fingerprints( $product );
			},
			function ( $client ) use ( $product ) {
				return Yotpo_Catalog_Api::compare_variants( $client, $product );
			}
		);
	}

	/**
	 * The policy both comparisons share. While Yotpo asked us to slow down, nothing is compared and
	 * $forget drops the fingerprints of what the comparison covers, so that everything is sent, as
	 * without a comparison. When the comparison fails, it drops the fingerprints it did not reach
	 * itself. A 429 also pauses every job.
	 *
	 * @param string   $what    For the log: what was being compared.
	 * @param callable $forget  Drops the fingerprints of what the comparison covers.
	 * @param callable $compare Receives the client; returns the WP_Error of the comparison, or null.
	 */
	private static function compare_or_send_all( $what, $forget, $compare ) {
		$client = Yotpo_V3_Client::from_settings();
		if ( ! $client ) {
			return;
		}
		if ( self::paused_for() > 0 ) {
			call_user_func( $forget );
			return;
		}
		$error = call_user_func( $compare, $client );
		if ( $error ) {
			// Attempt 1: only a 429 pauses, and its delay comes from Retry-After, not from the attempt.
			self::pause_if_rate_limited( $error, 1 );
			ytdbg( $error->get_error_message(), 'Product sync: ' . $what . ' failed, sending it all:' );
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
