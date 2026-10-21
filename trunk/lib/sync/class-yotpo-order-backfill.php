<?php

defined( 'ABSPATH' ) || exit;

/**
 * Sends orders Yotpo doesn't have yet, a page of orders at a time, in background jobs:
 *
 * - Send past orders: the merchant picks how many days back (1 to MAX_DAYS), sees how many orders that
 *   covers, and every order in the fulfilled status placed in that window is sent, unless Yotpo has it.
 * - The daily sweep: once a day, at a time fixed per store, fulfilled orders changed in the last
 *   SWEEP_DAYS days are sent if Yotpo still lacks them (a job that failed for good, an import that
 *   changed statuses without WooCommerce's hooks). Only orders fulfilled in that window and while
 *   sending was on: an older order that was merely edited is left alone.
 *
 * Both look a page up in one request and create only what is missing, so orders Yotpo already has
 * (sent by the live jobs or by 1.x) cost nothing more. Both run in Yotpo_Order_Sync's job group, behind
 * the live order jobs, and wait out its 429 pause.
 */
class Yotpo_Order_Backfill {

	// STATE_OPTION, SWEEP_CHECK and SETTLED_PREFIX are also written out in uninstall.php, which runs without this class loaded.
	const STATE_OPTION    = 'wc_yotpo_order_backfill';
	const SWEEP_CHECK     = 'wc_yotpo_order_sweep_check'; // Throttles the check that the daily sweep is scheduled.
	const SETTLED_PREFIX  = 'wc_yotpo_order_settled_'; // Transient per order (and change of it) the sweep could not send for good; also written out in uninstall.php.
	const HOOK_PAGE       = 'wc_yotpo_send_past_orders_page';
	const HOOK_SWEEP      = 'wc_yotpo_order_sweep';
	const HOOK_SWEEP_PAGE = 'wc_yotpo_order_sweep_page';
	const PAGE_SIZE       = Yotpo_V3_Client::LOOKUP_MAX; // One lookup request covers a page.
	const MAX_DAYS        = Yotpo_Order_Api::MAX_AGE_DAYS;
	const DEFAULT_DAYS    = 90; // 1.x's fixed window.
	const SWEEP_DAYS      = 7;
	const JOB_BUDGET      = 15; // Seconds a page job keeps creating orders before it hands the rest of its page to a new job.
	const STALL_AFTER     = 3600; // Seconds without progress after which a run with no job left counts as dead; its own, apart from the product sync's.

	const STATUS_RUNNING  = 'running';
	const STATUS_COMPLETE = 'complete';
	const STATUS_STOPPED  = 'stopped';

	const REASON_OFF = 'Stopped: "Send orders to Yotpo" was turned off.';

	public static function init() {
		add_action( self::HOOK_PAGE, array( __CLASS__, 'run_page_job' ), 10, 3 );
		add_action( self::HOOK_SWEEP, array( __CLASS__, 'run_sweep_job' ) );
		add_action( self::HOOK_SWEEP_PAGE, array( __CLASS__, 'run_sweep_page_job' ), 10, 4 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule_sweep' ), 20 ); // Not init: storefront requests do no extra reads.
		add_action( 'wp_ajax_wc_yotpo_past_orders_count', array( __CLASS__, 'ajax_count' ) );
		add_action( 'wc_yotpo_order_sync_setting_changed', array( __CLASS__, 'setting_changed' ), 10, 2 );
	}

	/**
	 * wc_yotpo_order_sync_setting_changed (Yotpo_Order_Sync::setting_changed()): turning sending off ends
	 * a run; turning it on schedules the daily sweep again on the next admin page load. (The off switch
	 * has already cancelled the queued jobs, the sweep and the run's pages with them.)
	 */
	public static function setting_changed( $was_on, $is_on ) {
		if ( $was_on && ! $is_on ) {
			self::stop( self::REASON_OFF );
		} elseif ( ! $was_on && $is_on ) {
			self::forget_sweep_check();
		}
	}

	// ---------------------------------------------------------------------------------------
	// Send past orders
	// ---------------------------------------------------------------------------------------

	/**
	 * Orders a run of $days days would cover: those in the fulfilled status placed in that window.
	 * Orders Yotpo already has are among them; they are skipped when the run reaches them.
	 */
	public static function count( $days ) {
		$result = wc_get_orders( self::window_query( self::valid_days( $days ), array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) ) );
		return (int) $result->total;
	}

	/**
	 * admin-ajax: the count for the days the merchant typed, before they start a run.
	 */
	public static function ajax_count() {
		check_ajax_referer( 'wc_yotpo_past_orders_count' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : self::DEFAULT_DAYS;
		wp_send_json_success( array( 'days' => self::valid_days( $days ), 'count' => self::count( $days ) ) );
	}

	/**
	 * Starts a run over the last $days days.
	 *
	 * @return true|WP_Error
	 */
	public static function start( $days ) {
		if ( ! Yotpo_Order_Sync::enabled() ) {
			return new WP_Error( 'yotpo_order_sync_off', 'Turn on "Send orders to Yotpo" and save first.' );
		}
		if ( ! Yotpo_Sync_Jobs::scheduler_ready() ) {
			return new WP_Error( 'yotpo_no_scheduler', 'Action Scheduler is not available.' );
		}
		self::end_if_stalled();
		if ( self::running() ) {
			return new WP_Error( 'yotpo_backfill_busy', 'Past orders are being sent already.' );
		}
		$days = (int) $days;
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			return new WP_Error( 'yotpo_backfill_days', 'Choose between 1 and ' . self::MAX_DAYS . ' days.' );
		}
		self::save_state(
			array(
				'status'  => self::STATUS_RUNNING,
				'days'    => $days,
				'since'   => time() - $days * DAY_IN_SECONDS,
				'started' => time(),
				'total'   => self::count( $days ),
				'checked' => 0,
				'sent'    => 0,
				'already' => 0,
				'skipped' => 0,
				'failed'  => 0,
				'offset'  => 0,
			)
		);
		Yotpo_Sync_Jobs::enqueue( self::HOOK_PAGE, array( 1, 1 ), Yotpo_Order_Sync::GROUP );
		ytdbg( $days . ' days', 'Order sync: sending past orders,' );
		return true;
	}

	/**
	 * One page of a run. Resumes at the order an interrupted attempt stopped at (state 'offset'), so a
	 * retry neither sends nor counts an order twice. A page is up to PAGE_SIZE creates, each a blocking
	 * request, so a job stops after JOB_BUDGET seconds and hands the rest of the page to a new job.
	 *
	 * @param int $page    The page of the run's orders.
	 * @param int $attempt Attempts at this page since it last made progress.
	 * @param int $resumed How often this page was continued after using its time budget. Makes each
	 *                     continuation a job of its own: Action Scheduler drops a job queued with the
	 *                     arguments of the one that is running.
	 */
	public static function run_page_job( $page, $attempt = 1, $resumed = 0 ) {
		$state = self::state();
		if ( self::STATUS_RUNNING !== $state['status'] ) {
			return;
		}
		if ( ! Yotpo_Order_Sync::enabled() ) {
			self::stop( self::REASON_OFF );
			return;
		}
		$paused_for = Yotpo_Sync_Jobs::paused_for();
		if ( $paused_for > 0 ) {
			Yotpo_Sync_Jobs::enqueue( self::HOOK_PAGE, array( (int) $page, (int) $attempt, (int) $resumed ), Yotpo_Order_Sync::GROUP, $paused_for );
			return;
		}
		$orders = wc_get_orders(
			array_merge(
				self::window_query( 0, array( 'limit' => self::PAGE_SIZE, 'page' => (int) $page, 'orderby' => 'ID', 'order' => 'ASC' ) ),
				array( 'date_created' => '>=' . (int) $state['since'] )
			)
		);
		$todo   = array_slice( $orders, (int) $state['offset'] );
		$counts = array( 'sent' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0, 'done' => 0 );
		$error  = self::send_missing( $todo, $counts, false, self::deadline() );

		$state             = self::state();
		$state['checked'] += $counts['done'];
		foreach ( array( 'sent', 'already', 'skipped', 'failed' ) as $key ) {
			$state[ $key ] += $counts[ $key ];
		}
		if ( $error ) {
			// Retryable: try the rest of this page again later.
			$state['offset'] += $counts['done'];
			Yotpo_Sync_Jobs::pause_if_rate_limited( $error, $attempt );
			if ( $attempt < Yotpo_Sync_Jobs::MAX_ATTEMPTS ) {
				self::save_state( $state );
				Yotpo_Sync_Jobs::enqueue( self::HOOK_PAGE, array( (int) $page, $attempt + 1, (int) $resumed ), Yotpo_Order_Sync::GROUP, Yotpo_Sync_Jobs::retry_delay( $error, $attempt ) );
				return;
			}
			self::save_state( $state );
			self::stop( 'Stopped: ' . $error->get_error_message() . ' Run it again to send the rest; orders already sent are skipped.' );
			return;
		}
		if ( ! empty( $counts['more'] ) ) {
			// The time budget is used up: the rest of the page goes to a new job, which starts at the next order.
			$state['offset'] += $counts['done'];
			self::save_state( $state );
			Yotpo_Sync_Jobs::enqueue( self::HOOK_PAGE, array( (int) $page, 1, (int) $resumed + 1 ), Yotpo_Order_Sync::GROUP );
			return;
		}
		$state['offset'] = 0;
		if ( count( $orders ) === self::PAGE_SIZE ) {
			self::save_state( $state );
			Yotpo_Sync_Jobs::enqueue( self::HOOK_PAGE, array( (int) $page + 1, 1 ), Yotpo_Order_Sync::GROUP );
			return;
		}
		$state['status']   = self::STATUS_COMPLETE;
		$state['finished'] = time();
		self::save_state( $state );
		ytdbg( wp_json_encode( $state ), 'Order sync: past orders sent,' );
	}

	/**
	 * Whether a run is going. A pure read: a run whose job died is ended by end_if_stalled(), which the
	 * callers that need a fresh answer (start(), the settings page) call first.
	 */
	public static function running() {
		return self::STATUS_RUNNING === self::state()['status'];
	}

	/**
	 * Ends a run whose page job died without returning (killed at the PHP time limit, a fatal error): it
	 * has no job left and makes no progress, and after STALL_AFTER it is ended here, so the merchant can
	 * start again instead of finding the button disabled for good. Writes the state when it ends a run.
	 */
	public static function end_if_stalled() {
		$state = self::state();
		if ( self::STATUS_RUNNING !== $state['status'] ) {
			return;
		}
		$last_progress = max( isset( $state['last_activity'] ) ? (int) $state['last_activity'] : 0, isset( $state['started'] ) ? (int) $state['started'] : 0 );
		if ( time() - $last_progress > self::STALL_AFTER && ! self::page_job_left() ) {
			self::stop( 'Stopped: a background job ended without finishing. Run it again to send the rest; orders already sent are skipped.' );
			ytdbg( '', 'Order sync: past orders run stalled,' );
		}
	}

	/**
	 * Whether a page job of a run is queued or running. Unknown (no Action Scheduler) counts as yes.
	 */
	private static function page_job_left() {
		if ( ! Yotpo_Sync_Jobs::scheduler_ready() || ! class_exists( 'ActionScheduler' ) ) {
			return true;
		}
		return Yotpo_Sync_Jobs::has_jobs( Yotpo_Order_Sync::GROUP, self::HOOK_PAGE );
	}

	/**
	 * Ends a run early (the switch was turned off, or Yotpo kept failing).
	 */
	public static function stop( $reason ) {
		$state = self::state();
		if ( self::STATUS_RUNNING !== $state['status'] ) {
			return;
		}
		$state['status']   = self::STATUS_STOPPED;
		$state['finished'] = time();
		$state['reason']   = $reason;
		self::save_state( $state );
	}

	/**
	 * @return array The last run's state; 'status' is '' before the first run.
	 */
	public static function state() {
		wp_cache_delete( self::STATE_OPTION, 'options' ); // A job in another runner may have saved since.
		$state = get_option( self::STATE_OPTION );
		return array_merge( array( 'status' => '' ), is_array( $state ) ? $state : array() );
	}

	private static function save_state( array $state ) {
		$state['last_activity'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * One line under Send past orders: the run going on, or how the last one went.
	 */
	public static function status_summary() {
		$state = self::state();
		if ( '' === $state['status'] ) {
			return '';
		}
		$counts = sprintf( '%d sent, %d already in Yotpo, %d skipped, %d failed.', $state['sent'], $state['already'], $state['skipped'], $state['failed'] );
		if ( self::STATUS_RUNNING === $state['status'] ) {
			return sprintf( 'Sending past orders: %d of %d checked. %s', $state['checked'], $state['total'], $counts );
		}
		$line = sprintf( 'Last run, %s, last %d days: %s', wp_date( 'j M Y, H:i', (int) $state['finished'] ), $state['days'], $counts );
		return self::STATUS_STOPPED === $state['status'] ? $line . ' ' . $state['reason'] : $line;
	}

	public static function status_tone() {
		$state = self::state();
		if ( self::STATUS_RUNNING === $state['status'] ) {
			return 'info';
		}
		return self::STATUS_STOPPED === $state['status'] || ! empty( $state['failed'] ) ? 'warning' : 'success';
	}

	// ---------------------------------------------------------------------------------------
	// Daily sweep
	// ---------------------------------------------------------------------------------------

	/**
	 * admin_init: makes sure the daily sweep is scheduled while sending is on. The first run is at a random
	 * time of day, and then every 24 hours, so stores don't all call Yotpo at once. The queue is checked
	 * at most once a day (SWEEP_CHECK). Turning sending off removes it with the other order jobs.
	 */
	public static function maybe_schedule_sweep() {
		if ( get_transient( self::SWEEP_CHECK ) || ! Yotpo_Sync_Jobs::scheduler_ready() || ! Yotpo_Order_Sync::enabled() ) {
			return;
		}
		set_transient( self::SWEEP_CHECK, 1, DAY_IN_SECONDS );
		if ( ! as_has_scheduled_action( self::HOOK_SWEEP, array(), Yotpo_Order_Sync::GROUP ) ) {
			as_schedule_recurring_action( time() + wp_rand( 0, DAY_IN_SECONDS ), DAY_IN_SECONDS, self::HOOK_SWEEP, array(), Yotpo_Order_Sync::GROUP, true );
		}
	}

	/**
	 * Forgets that the sweep's schedule was checked, so the next admin page load checks it.
	 */
	private static function forget_sweep_check() {
		delete_transient( self::SWEEP_CHECK );
	}

	/**
	 * The daily sweep: starts at page 1 of the orders changed since the window opened.
	 */
	public static function run_sweep_job() {
		if ( ! Yotpo_Order_Sync::enabled() ) {
			return;
		}
		$stats = Yotpo_Order_Sync::stats();
		$since = max( time() - self::SWEEP_DAYS * DAY_IN_SECONDS, isset( $stats['enabled_at'] ) ? (int) $stats['enabled_at'] : 0 );
		Yotpo_Sync_Jobs::enqueue( self::HOOK_SWEEP_PAGE, array( 1, $since, 1 ), Yotpo_Order_Sync::GROUP );
	}

	/**
	 * One page of the sweep: the fulfilled orders changed since $since, of which those fulfilled since then
	 * (their completion date, Yotpo_Order_Api::fulfilled_at()) are considered. Orders with a stored Yotpo ID
	 * were sent and cost no request; the rest are looked up together and the missing ones created. Sent and failed orders count in the status line,
	 * as the live jobs' do. A retryable error retries the page later; after MAX_ATTEMPTS the sweep ends
	 * and tomorrow's picks the orders up.
	 */
	public static function run_sweep_page_job( $page, $since, $attempt = 1, $offset = 0 ) {
		if ( ! Yotpo_Order_Sync::enabled() ) {
			return;
		}
		$paused_for = Yotpo_Sync_Jobs::paused_for();
		if ( $paused_for > 0 ) {
			Yotpo_Sync_Jobs::enqueue( self::HOOK_SWEEP_PAGE, array( (int) $page, (int) $since, (int) $attempt, (int) $offset ), Yotpo_Order_Sync::GROUP, $paused_for );
			return;
		}
		$orders = wc_get_orders(
			array(
				'status'        => Yotpo_Order_Sync::fulfilled_status(),
				'date_modified' => '>=' . (int) $since,
				'limit'         => self::PAGE_SIZE,
				'page'          => (int) $page,
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'type'          => 'shop_order',
			)
		);
		// The query finds orders changed since the window opened; of those only the ones fulfilled in it are
		// sent. An old order that was merely edited (a note, a refund, a plugin saving it) is not.
		$fulfilled = array_values(
			array_filter(
				$orders,
				function ( $order ) use ( $since ) {
					return Yotpo_Order_Api::fulfilled_at( $order ) >= (int) $since;
				}
			)
		);
		$counts = array( 'sent' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0, 'done' => 0 );
		$error  = self::send_missing( array_slice( $fulfilled, (int) $offset ), $counts, true, self::deadline() );
		if ( $error ) {
			Yotpo_Sync_Jobs::pause_if_rate_limited( $error, $attempt );
			if ( $attempt < Yotpo_Sync_Jobs::MAX_ATTEMPTS ) {
				// Orders created so far store their Yotpo ID, so the retry does not send them again.
				Yotpo_Sync_Jobs::enqueue( self::HOOK_SWEEP_PAGE, array( (int) $page, (int) $since, $attempt + 1, (int) $offset + $counts['done'] ), Yotpo_Order_Sync::GROUP, Yotpo_Sync_Jobs::retry_delay( $error, $attempt ) );
			} else {
				ytdbg( $error->get_error_message(), 'Order sync: daily sweep stopped,' );
			}
			return;
		}
		if ( $counts['sent'] || $counts['failed'] ) {
			ytdbg( $counts['sent'] . ' sent, ' . $counts['failed'] . ' failed', 'Order sync: daily sweep page ' . $page . ',' );
		}
		if ( ! empty( $counts['more'] ) ) {
			// The time budget is used up: the rest of the page goes to a new job, which starts at the next order.
			Yotpo_Sync_Jobs::enqueue( self::HOOK_SWEEP_PAGE, array( (int) $page, (int) $since, 1, (int) $offset + $counts['done'] ), Yotpo_Order_Sync::GROUP );
			return;
		}
		if ( count( $orders ) === self::PAGE_SIZE ) {
			Yotpo_Sync_Jobs::enqueue( self::HOOK_SWEEP_PAGE, array( (int) $page + 1, (int) $since, 1 ), Yotpo_Order_Sync::GROUP );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Shared
	// ---------------------------------------------------------------------------------------

	/**
	 * Sends the orders of a page that Yotpo doesn't have, in order. Orders with a stored Yotpo ID are
	 * counted as already there without a request; the rest are looked up in one request (which also
	 * stores the IDs found), and the missing ones created.
	 *
	 * @param WC_Order[] $orders
	 * @param array      $counts     Adds to sent, already, skipped, failed, and done (orders finished, in order).
	 * @param bool       $live_stats Count sent and failed orders in the status line (the sweep) too, and remember
	 *                               the ones that can't be sent for good, so the next sweep leaves them alone
	 *                               until they change (settled()).
	 * @param int        $deadline   Unix time after which no further order is created: $counts['more'] is
	 *                               set instead, and the caller continues with the orders not done.
	 *                               At least one order is done first, so a job always makes progress.
	 * @return WP_Error|null A retryable error, which stops the page at the first order not done.
	 */
	private static function send_missing( array $orders, array &$counts, $live_stats, $deadline ) {
		if ( ! $orders ) {
			return null;
		}
		$client = Yotpo_V3_Client::from_settings();
		$ask    = array();
		foreach ( $orders as $order ) {
			if ( ! $order->get_meta( Yotpo_Order_Api::META_ORDER_ID ) && ! ( $live_stats && self::settled( $order ) ) ) {
				$ask[] = $order;
			}
		}
		$found = $ask ? Yotpo_Order_Api::find_yotpo_ids( $client, $ask ) : array();
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		foreach ( $orders as $order ) {
			if ( $order->get_meta( Yotpo_Order_Api::META_ORDER_ID ) || isset( $found[ $order->get_id() ] ) ) {
				++$counts['already'];
				++$counts['done'];
				continue;
			}
			if ( $live_stats && self::settled( $order ) ) {
				++$counts['skipped'];
				++$counts['done'];
				continue;
			}
			if ( $counts['done'] && time() >= $deadline ) {
				$counts['more'] = true;
				return null;
			}
			$created = false;
			$result  = Yotpo_Order_Api::create_fulfilled( $client, $order, Yotpo_Order_Api::fulfilled_at( $order ), $created );
			if ( is_wp_error( $result ) ) {
				if ( Yotpo_V3_Client::is_retryable( $result ) ) {
					return $result;
				}
				if ( $live_stats ) {
					self::settle( $order );
				}
				if ( 'yotpo_order_skipped' === $result->get_error_code() ) {
					++$counts['skipped'];
				} else {
					++$counts['failed'];
					ytdbg( $result->get_error_message(), 'Order sync: order #' . $order->get_id() . ' failed:' );
					if ( $live_stats ) {
						Yotpo_Order_Sync::count_failed( $order, $result );
					}
				}
			} elseif ( $created ) {
				++$counts['sent'];
				if ( $live_stats ) {
					Yotpo_Order_Sync::count_sent( $order->get_id() );
				}
			} else {
				++$counts['already'];
			}
			++$counts['done'];
		}
		return null;
	}

	/**
	 * Remembers an order the sweep could not send for good (rejected by Yotpo, or not sendable, such as
	 * an order without an email). Until the order changes, later sweeps neither look it up again, nor
	 * send it again, nor count its failure again. The marker lasts as long as the sweep's window.
	 */
	private static function settle( $order ) {
		set_transient( self::settled_key( $order ), 1, self::SWEEP_DAYS * DAY_IN_SECONDS );
	}

	private static function settled( $order ) {
		return (bool) get_transient( self::settled_key( $order ) );
	}

	/**
	 * Named after the order and the time it last changed, so a merchant's fix (an email added, an item
	 * corrected) makes the order eligible again.
	 */
	private static function settled_key( $order ) {
		$modified = $order->get_date_modified();
		return self::SETTLED_PREFIX . $order->get_id() . '_' . ( $modified ? $modified->getTimestamp() : 0 );
	}

	/**
	 * The time a page job stops creating orders at (filterable: wc_yotpo_order_job_budget, in seconds).
	 */
	private static function deadline() {
		return time() + max( 0, (int) apply_filters( 'wc_yotpo_order_job_budget', self::JOB_BUDGET ) );
	}

	/**
	 * The query for the orders of a run: in the fulfilled status, placed in the last $days days (0: the
	 * caller sets the date).
	 */
	private static function window_query( $days, array $more ) {
		$query = array(
			'status' => Yotpo_Order_Sync::fulfilled_status(),
			'type'   => 'shop_order',
		);
		if ( $days ) {
			$query['date_created'] = '>=' . ( time() - $days * DAY_IN_SECONDS );
		}
		return array_merge( $query, $more );
	}

	private static function valid_days( $days ) {
		return max( 1, min( self::MAX_DAYS, (int) $days ) );
	}
}
