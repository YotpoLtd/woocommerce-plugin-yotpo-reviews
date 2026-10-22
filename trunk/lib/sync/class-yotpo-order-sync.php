<?php

defined( 'ABSPATH' ) || exit;

/**
 * Sends orders to Yotpo through the Core API (V3), in background jobs, so Yotpo can send review requests.
 *
 * - An order is sent once, when it reaches the status the merchant chose as fulfilled ("Send review
 *   requests when an order reaches"), with its fulfillment (Yotpo_Order_Api::send_fulfilled()).
 * - An order already in Yotpo that is cancelled or refunded afterwards gets an update, which carries
 *   the cancellation (Yotpo_Order_Api::send_update()).
 * - Nothing calls Yotpo while an order is being saved: WooCommerce's order hooks only queue a job.
 *   Order jobs run ahead of the product sync's jobs, and share its pause after a 429.
 *
 * The orders themselves live in Yotpo_Order_Api and Yotpo_Order_Payload.
 */
class Yotpo_Order_Sync {

	// GROUP, STATS_OPTION and LOCK_OPTION are also written out in uninstall.php, which runs without this class loaded.
	const GROUP          = 'yotpo-order-sync';
	const STATS_OPTION   = 'wc_yotpo_order_sync';
	const LOCK_OPTION    = 'wc_yotpo_order_sync_lock'; // Short-lived lock around changes to the stats; the product sync has its own.
	const HOOK_FULFILLED = 'wc_yotpo_send_order';
	const HOOK_UPDATE    = 'wc_yotpo_update_order';
	const PRIORITY       = 5; // Action Scheduler runs lower numbers first; the product sync's jobs have the default, 10.
	const STATS_DAYS     = 30; // Days of sent and failed counts kept for the status line.
	const FAILURES_KEPT  = 5; // Most recent failed orders kept for the status line.

	public static function init() {
		add_action( self::HOOK_FULFILLED, array( __CLASS__, 'run_fulfilled_job' ), 10, 3 );
		add_action( self::HOOK_UPDATE, array( __CLASS__, 'run_update_job' ), 10, 2 );
		// The switch is checked where jobs are queued and run, not here: a filter added after
		// plugins_loaded (theme, mu-plugin) must still take effect.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status_changed' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'queue_update' ) );
	}

	/**
	 * Orders are sent when the credentials are saved and "Send orders to Yotpo" is on. Also filterable
	 * as a kill switch (wc_yotpo_order_sync_enabled).
	 */
	public static function enabled() {
		$ready = Yotpo_V3_Client::from_settings() && self::setting_on();
		return (bool) apply_filters( 'wc_yotpo_order_sync_enabled', $ready );
	}

	/**
	 * The merchant's "Send orders to Yotpo" setting. On by default, including for settings saved before
	 * the setting existed: 1.x always sent orders.
	 */
	public static function setting_on() {
		return Yotpo_Sync_Jobs::setting_default_on( 'order_sync_enabled' );
	}

	/**
	 * The status that means fulfilled ("Send review requests when an order reaches"), without its
	 * "wc-" prefix, as WooCommerce's status hooks name it.
	 */
	public static function fulfilled_status() {
		$settings = get_option( 'yotpo_settings', array() );
		$status   = is_array( $settings ) && ! empty( $settings['yotpo_order_status'] ) ? (string) $settings['yotpo_order_status'] : 'wc-completed';
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Called after the settings are saved: turning orders off cancels the queued order jobs, which include
	 * the daily sweep and the pages of a Send past orders run. Turning them back on sends nothing by
	 * itself (orders fulfilled meanwhile need Send past orders); the time is kept so the daily sweep
	 * covers only orders fulfilled while sending was on.
	 *
	 * Fires wc_yotpo_order_sync_setting_changed( $was_on, $is_on ) for whatever else follows the switch
	 * (Yotpo_Order_Backfill ends its run), so this class doesn't depend on its users.
	 */
	public static function setting_changed( $was_on, $is_on ) {
		if ( $was_on && ! $is_on ) {
			self::cancel_all();
		} elseif ( ! $was_on && $is_on ) {
			self::update_stats(
				function ( $stats ) {
					$stats['enabled_at'] = time();
					return $stats;
				}
			);
		}
		do_action( 'wc_yotpo_order_sync_setting_changed', (bool) $was_on, (bool) $is_on );
	}

	// ---------------------------------------------------------------------------------------
	// Queueing
	// ---------------------------------------------------------------------------------------

	/**
	 * woocommerce_order_status_changed: an order reaching the fulfilled status is sent; for one cancelled
	 * or refunded an update job is queued. The job looks the order up in Yotpo and does nothing when
	 * Yotpo doesn't have it (Yotpo_Order_Api::send_update()). Both can apply: the merchant may have chosen
	 * Cancelled or Refunded as the status that sends the order, and the cancellation then follows the create.
	 */
	public static function order_status_changed( $order_id, $from, $to ) {
		if ( self::fulfilled_status() === $to ) {
			self::queue_fulfilled( $order_id, time() );
		}
		if ( in_array( $to, array( 'cancelled', 'refunded' ), true ) ) {
			self::queue_update( $order_id );
		}
	}

	/**
	 * @param int $fulfilled_at Unix time the order reached the fulfilled status.
	 * @return int The queued action ID (0 when nothing was queued).
	 */
	public static function queue_fulfilled( $order_id, $fulfilled_at, $attempt = 1, $delay = 0 ) {
		return self::queue( self::HOOK_FULFILLED, array( (int) $order_id, (int) $fulfilled_at, (int) $attempt ), $delay );
	}

	/**
	 * Also hooked to woocommerce_order_refunded: a refund changes the payment status, and a full one
	 * cancels the order's review requests.
	 *
	 * @return int The queued action ID (0 when nothing was queued).
	 */
	public static function queue_update( $order_id, $attempt = 1, $delay = 0 ) {
		return self::queue( self::HOOK_UPDATE, array( (int) $order_id, (int) $attempt ), $delay );
	}

	private static function queue( $hook, array $args, $delay ) {
		if ( ! Yotpo_Sync_Jobs::scheduler_ready() || ! self::enabled() ) {
			return 0;
		}
		return Yotpo_Sync_Jobs::enqueue( $hook, $args, self::GROUP, $delay, self::PRIORITY );
	}

	// ---------------------------------------------------------------------------------------
	// Jobs
	// ---------------------------------------------------------------------------------------

	public static function run_fulfilled_job( $order_id, $fulfilled_at, $attempt = 1 ) {
		self::run(
			$order_id,
			$attempt,
			function ( $client, $order ) use ( $order_id, $fulfilled_at ) {
				$created = false;
				$result  = Yotpo_Order_Api::send_fulfilled( $client, $order, $fulfilled_at, $created );
				// Only an order created now counts as sent: one Yotpo already had (sent by 1.x, or by an
				// earlier job) was not sent again.
				if ( $created ) {
					self::after_create( $order_id );
				}
				return $result;
			},
			function ( $next_attempt, $delay ) use ( $order_id, $fulfilled_at ) {
				self::queue_fulfilled( $order_id, $fulfilled_at, $next_attempt, $delay );
			}
		);
	}

	/**
	 * What follows an order created in Yotpo: it counts as sent, and a cancellation the create left out
	 * is sent. A create never carries the cancellation. If the order was cancelled or refunded before or
	 * while the job ran, the update job queued for that may have run first and found nothing in Yotpo.
	 * The order is read again, as the one the job loaded is as old as the create's round trips. The
	 * fingerprint stored by the create makes the update a no-op for an order that is not cancelled.
	 */
	private static function after_create( $order_id ) {
		self::count_sent( $order_id );
		$order = wc_get_order( $order_id );
		if ( $order && Yotpo_Order_Payload::is_cancelled( $order ) ) {
			self::queue_update( $order_id );
		}
	}

	public static function run_update_job( $order_id, $attempt = 1 ) {
		self::run(
			$order_id,
			$attempt,
			function ( $client, $order ) {
				return Yotpo_Order_Api::send_update( $client, $order );
			},
			function ( $next_attempt, $delay ) use ( $order_id ) {
				self::queue_update( $order_id, $next_attempt, $delay );
			}
		);
	}

	/**
	 * What both jobs share: the switch, the pause after a 429, retries with growing delays and the log.
	 * Counting an order as sent is up to the fulfilled job's $send.
	 *
	 * @param callable $send    Receives the client and the order; returns the Yotpo order ID (0 when
	 *                          Yotpo does not have the order) or a WP_Error.
	 * @param callable $requeue Receives the next attempt and the delay in seconds.
	 */
	private static function run( $order_id, $attempt, $send, $requeue ) {
		if ( ! self::enabled() ) {
			return;
		}
		$client = Yotpo_V3_Client::from_settings();
		$order  = wc_get_order( $order_id );
		if ( ! $client || ! $order ) {
			return;
		}
		// Yotpo asked every caller to slow down: wait without spending an attempt.
		$paused_for = Yotpo_Sync_Jobs::paused_for();
		if ( $paused_for > 0 ) {
			call_user_func( $requeue, $attempt, $paused_for );
			return;
		}
		$result = call_user_func( $send, $client, $order );
		Yotpo_Sync_Jobs::pause_if_rate_limited( $result, $attempt );
		$what = 'Order sync: order #' . $order_id;
		if ( ! is_wp_error( $result ) ) {
			ytdbg( $result ? 'Yotpo ID ' . $result : 'Yotpo does not have the order', $what . ' done,' );
			return;
		}
		if ( 'yotpo_order_skipped' === $result->get_error_code() ) {
			ytdbg( $result->get_error_message(), $what . ':' );
			return;
		}
		if ( Yotpo_V3_Client::is_retryable( $result ) && $attempt < Yotpo_Sync_Jobs::MAX_ATTEMPTS ) {
			call_user_func( $requeue, $attempt + 1, Yotpo_Sync_Jobs::retry_delay( $result, $attempt ) );
			return;
		}
		ytdbg( $result->get_error_message(), $what . ' failed:' );
		self::count_failed( $order, $result );
	}

	/**
	 * Cancels every queued order job (used when the merchant turns sending off).
	 */
	public static function cancel_all() {
		Yotpo_Sync_Jobs::cancel_group( self::GROUP );
	}

	/**
	 * The store switched to another Yotpo account (Yotpo_Account_Switch): the queued order jobs, the daily
	 * sweep with them, were about the old one, and the counts start over. The time is the new start of the
	 * sweep's window, so it goes back no further than the switch.
	 */
	public static function account_switched() {
		self::cancel_all();
		self::update_stats(
			function () {
				return array(
					'enabled_at' => time(),
					'days'       => array(),
					'failures'   => array(),
				);
			}
		);
	}

	// ---------------------------------------------------------------------------------------
	// Status line
	// ---------------------------------------------------------------------------------------

	/**
	 * The counts behind the status line: the last order sent, sent and failed orders per day for the
	 * last STATS_DAYS days, and the last FAILURES_KEPT failures.
	 *
	 * @return array{last_sent?:int, enabled_at?:int, days:array, failures:array}
	 */
	public static function stats() {
		$stats = get_option( self::STATS_OPTION );
		$stats = is_array( $stats ) ? $stats : array();
		return array_merge( array( 'days' => array(), 'failures' => array() ), $stats );
	}

	/**
	 * The oldest day (Y-m-d, UTC) still counted: pruning and the totals use the same window.
	 */
	private static function oldest_counted_day() {
		return gmdate( 'Y-m-d', time() - ( self::STATS_DAYS - 1 ) * DAY_IN_SECONDS );
	}

	private static function save_stats( array $stats ) {
		$oldest = self::oldest_counted_day();
		foreach ( array_keys( $stats['days'] ) as $day ) {
			if ( $day < $oldest ) {
				unset( $stats['days'][ $day ] );
			}
		}
		$since             = time() - self::STATS_DAYS * DAY_IN_SECONDS;
		$stats['failures'] = array_slice(
			array_values(
				array_filter(
					$stats['failures'],
					function ( $failure ) use ( $since ) {
						return (int) $failure['time'] >= $since;
					}
				)
			),
			0,
			self::FAILURES_KEPT
		);
		update_option( self::STATS_OPTION, $stats, false ); // Not autoloaded: only jobs and the settings page read it.
	}

	/**
	 * Changes the counts without losing a concurrent change: Action Scheduler can run several queue
	 * runners at once, and each would otherwise overwrite what the others just saved. Takes its own lock,
	 * so the stats never wait for the product sync.
	 *
	 * @param callable $change Receives the stats; returns the stats to save.
	 */
	private static function update_stats( $change ) {
		Yotpo_Option_Lock::run(
			self::LOCK_OPTION,
			function () use ( $change ) {
				// Another runner may have saved since this request loaded the option.
				wp_cache_delete( self::STATS_OPTION, 'options' );
				self::save_stats( call_user_func( $change, self::stats() ) );
			}
		);
	}

	/**
	 * Counts an order as sent for the status line. Also used by the daily sweep.
	 */
	public static function count_sent( $order_id ) {
		self::update_stats(
			function ( $stats ) use ( $order_id ) {
				$stats['last_sent'] = time();
				self::add_to_today( $stats, 'sent' );
				// Sent after all (a later attempt, or Send past orders): no longer a failure, so it no longer
				// counts as one on the day it failed either.
				$kept = array();
				foreach ( $stats['failures'] as $failure ) {
					if ( (int) $failure['order_id'] !== (int) $order_id ) {
						$kept[] = $failure;
						continue;
					}
					$day = gmdate( 'Y-m-d', (int) $failure['time'] );
					if ( ! empty( $stats['days'][ $day ]['failed'] ) ) {
						--$stats['days'][ $day ]['failed'];
					}
				}
				$stats['failures'] = $kept;
				return $stats;
			}
		);
	}

	/**
	 * Counts an order as failed for the status line, and keeps it among the last failures. Also used by
	 * the daily sweep.
	 */
	public static function count_failed( $order, $error ) {
		self::update_stats(
			function ( $stats ) use ( $order, $error ) {
				self::add_to_today( $stats, 'failed' );
				array_unshift(
					$stats['failures'],
					array(
						'order_id' => $order->get_id(),
						'number'   => (string) $order->get_order_number(),
						'error'    => $error->get_error_message(),
						'time'     => time(),
					)
				);
				return $stats;
			}
		);
	}

	private static function add_to_today( array &$stats, $count ) {
		$today = gmdate( 'Y-m-d' );
		if ( ! isset( $stats['days'][ $today ] ) ) {
			$stats['days'][ $today ] = array( 'sent' => 0, 'failed' => 0 );
		}
		++$stats['days'][ $today ][ $count ];
	}

	/**
	 * Sent and failed orders over the last STATS_DAYS days.
	 *
	 * @return array{sent:int, failed:int}
	 */
	public static function totals() {
		$totals = array( 'sent' => 0, 'failed' => 0 );
		$oldest = self::oldest_counted_day();
		foreach ( self::stats()['days'] as $day => $counts ) {
			if ( $day >= $oldest ) {
				$totals['sent']   += (int) $counts['sent'];
				$totals['failed'] += (int) $counts['failed'];
			}
		}
		return $totals;
	}

	/**
	 * One line for the settings page: whether orders are sent, and how the last 30 days went.
	 */
	public static function status_summary() {
		if ( ! self::setting_on() ) {
			return 'Off. Orders are not sent to Yotpo.';
		}
		if ( ! Yotpo_V3_Client::from_settings() ) {
			return 'Starts once the app key and secret token are saved.';
		}
		if ( ! self::enabled() ) {
			return 'Turned off by the wc_yotpo_order_sync_enabled filter.';
		}
		$stats = self::stats();
		if ( empty( $stats['last_sent'] ) && ! $stats['failures'] ) {
			return 'On. No orders sent yet.';
		}
		$totals = self::totals();
		$line   = sprintf(
			'%s %d sent in the last %d days, %d failed.',
			empty( $stats['last_sent'] ) ? 'No order sent yet.' : 'Last order sent: ' . wp_date( 'j M Y, H:i', (int) $stats['last_sent'] ) . '.',
			$totals['sent'],
			self::STATS_DAYS,
			$totals['failed']
		);
		if ( $stats['failures'] ) {
			$last  = $stats['failures'][0];
			$line .= sprintf( ' Last failure: order #%s, %s', $last['number'], $last['error'] );
		}
		return $line;
	}

	/**
	 * The status line's tone: neutral when off, info before the first order, warning when an order
	 * failed in the last 30 days, else success.
	 */
	public static function status_tone() {
		if ( ! self::enabled() ) {
			return 'neutral';
		}
		$totals = self::totals();
		if ( $totals['failed'] ) {
			return 'warning';
		}
		return empty( self::stats()['last_sent'] ) ? 'info' : 'success';
	}
}
