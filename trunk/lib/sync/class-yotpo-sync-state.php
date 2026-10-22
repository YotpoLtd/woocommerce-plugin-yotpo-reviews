<?php

defined( 'ABSPATH' ) || exit;

/**
 * The state of the full catalog sync (an autoloaded option) and its counters, changed under a
 * short-lived lock because product jobs of one full sync can run in parallel.
 */
class Yotpo_Sync_State {

	// Also written out in uninstall.php, which runs without this class loaded: keep both in sync.
	const STATE_OPTION    = 'wc_yotpo_product_sync';
	const LOCK_OPTION     = 'wc_yotpo_product_sync_lock'; // Short-lived lock around changes to the sync state.
	const STATUS_RUNNING  = 'running';
	const STATUS_STOPPED  = 'stopped'; // Cut short by the off switch; starts again once the switch is back on.
	const STATUS_COMPLETE = 'complete';
	const STATUS_STALLED  = 'stalled'; // Restarted automatically too often; waits for the merchant to retry.

	public static function get() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Starts a new full sync for the given app key, with all counters at zero.
	 *
	 * @param int $stall_restarts How many times a stalled sync was restarted automatically (0 for a normal start).
	 */
	public static function start( $app_key, $stall_restarts = 0 ) {
		self::update(
			function () use ( $app_key, $stall_restarts ) {
				return array(
					'app_key'       => $app_key,
					'status'        => self::STATUS_RUNNING,
					'started'       => time(),
					'finished'      => 0,
					'synced'        => 0,
					'failed'        => 0,
					'skipped'       => 0,
					'total'         => 0,
					'pages_done'    => false, // True once the last page of products is queued.
					'last_activity' => 0,
					'last_error'    => $stall_restarts ? 'The previous full sync stalled (a product job ended without finishing); restarted it.' : '',
					'stall_restarts' => (int) $stall_restarts,
				);
			}
		);
	}

	/**
	 * Adds the product jobs one page of the full sync queued; $more is false for the last page.
	 */
	public static function page_queued( $queued, $more ) {
		self::update(
			function ( $state ) use ( $queued, $more ) {
				$state['total'] = ( isset( $state['total'] ) ? (int) $state['total'] : 0 ) + $queued;
				if ( ! $more ) {
					$state['pages_done'] = true;
				}
				return self::finish_if_done( $state );
			}
		);
	}

	public static function count_result( $result ) {
		self::update(
			function ( $state ) use ( $result ) {
				if ( empty( $state ) ) {
					return null;
				}
				if ( is_wp_error( $result ) ) {
					$state['failed']     = (int) $state['failed'] + 1;
					$state['last_error'] = $result->get_error_message();
				} elseif ( $result ) {
					$state['synced'] = (int) $state['synced'] + 1;
				} else {
					// Nothing to do: the product was unpublished or removed after the sync was queued.
					$state['skipped'] = ( isset( $state['skipped'] ) ? (int) $state['skipped'] : 0 ) + 1;
				}
				$state['last_activity'] = time();
				return self::finish_if_done( $state );
			}
		);
	}

	/**
	 * A running full sync that can no longer continue (the off switch is on) is marked stopped,
	 * so maybe_start_initial_sync() can start it again instead of leaving it 'running' for good.
	 */
	public static function stop() {
		self::update(
			function ( $state ) {
				if ( isset( $state['status'] ) && self::STATUS_RUNNING === $state['status'] ) {
					$state['status'] = self::STATUS_STOPPED;
					return $state;
				}
				return null;
			}
		);
	}

	/**
	 * Forgets the sync state, so the next full sync starts from nothing: it was about another Yotpo
	 * account (Yotpo_Account_Switch).
	 */
	public static function forget() {
		Yotpo_Option_Lock::run(
			self::LOCK_OPTION,
			function () {
				delete_option( self::STATE_OPTION );
			}
		);
	}

	/**
	 * Asks for another full sync once this one is complete (Yotpo_Sync_Jobs::request_full_sync()).
	 * start() clears it, since the new state has no such flag.
	 */
	public static function request_rerun() {
		self::update(
			function ( $state ) {
				if ( empty( $state ) ) {
					return null;
				}
				$state['rerun'] = true;
				return $state;
			}
		);
	}

	/**
	 * A full sync that kept stalling is left for the merchant: turning the setting off and on starts it again.
	 */
	public static function mark_stalled( $restarts ) {
		self::update(
			function ( $state ) use ( $restarts ) {
				if ( ! isset( $state['status'] ) || self::STATUS_RUNNING !== $state['status'] ) {
					return null;
				}
				$state['status']     = self::STATUS_STALLED;
				$state['finished']   = time();
				$state['last_error'] = sprintf( 'The full sync stalled %d times: a product job keeps ending without finishing (see the PHP error log). Turn "Sync products to Yotpo" off and on to try again.', $restarts );
				return $state;
			}
		);
	}

	/**
	 * Marks a running full sync complete once every page is queued and every queued product job finished.
	 */
	private static function finish_if_done( $state ) {
		if ( isset( $state['status'] ) && self::STATUS_RUNNING === $state['status'] && ! empty( $state['pages_done'] )
			&& (int) $state['synced'] + (int) $state['failed'] + ( isset( $state['skipped'] ) ? (int) $state['skipped'] : 0 ) >= (int) $state['total'] ) {
			$state['status']   = self::STATUS_COMPLETE;
			$state['finished'] = time();
			ytdbg( $state['synced'] . ' synced, ' . $state['failed'] . ' failed', 'Product sync: full sync done:' );
		}
		return $state;
	}

	/**
	 * Changes the sync state without losing a concurrent change: product jobs of one full sync can
	 * run in parallel, and each would otherwise overwrite the counters the others just saved.
	 *
	 * @param callable $change Receives the current state; returns the state to save, or null to save nothing.
	 */
	private static function update( $change ) {
		Yotpo_Option_Lock::run(
			self::LOCK_OPTION,
			function () use ( $change ) {
				wp_cache_delete( self::STATE_OPTION, 'options' );
				$new_state = call_user_func( $change, self::get() );
				if ( is_array( $new_state ) ) {
					self::save( $new_state );
				}
			}
		);
	}

	private static function save( $state ) {
		// Autoloaded: maybe_start_initial_sync() reads it on every request.
		update_option( self::STATE_OPTION, $state, true );
	}
}
