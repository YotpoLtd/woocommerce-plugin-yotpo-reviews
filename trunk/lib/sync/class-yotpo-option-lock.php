<?php

defined( 'ABSPATH' ) || exit;

/**
 * A short-lived lock for read-change-save updates to options, because Action Scheduler can run several
 * queue runners at once and each would otherwise overwrite what the others just saved. Each user of it
 * names its own lock option, so unrelated updates never wait for each other.
 *
 * Used by Yotpo_Sync_State (the full sync's counters) and Yotpo_Order_Sync (its counts).
 */
class Yotpo_Option_Lock {

	const TTL        = 30; // Seconds after which a lock left behind by a killed job is taken over.
	const WAIT_TRIES = 50; // Attempts to take the lock, WAIT_USEC apart: five seconds in all.
	const WAIT_USEC  = 100000;

	/**
	 * Runs a change to options while holding the lock (best effort, see lock()).
	 *
	 * @param string   $lock_option The option that holds this lock.
	 * @param callable $change      Called with no arguments, once the lock is taken; it re-reads what it changes.
	 * @return mixed What $change returns.
	 */
	public static function run( $lock_option, $change ) {
		$token = self::lock( $lock_option );
		// Another runner may have saved since this request loaded its options.
		wp_cache_delete( 'alloptions', 'options' );
		$result = call_user_func( $change );
		if ( $token ) {
			self::unlock( $lock_option, $token );
		}
		return $result;
	}

	/**
	 * Takes the lock (an option that add_option() only creates once). Waits up to five seconds
	 * (WAIT_TRIES x WAIT_USEC); a lock older than TTL was left by a killed job and is taken over.
	 * Best effort: after the wait the change goes ahead without the lock rather than being lost.
	 *
	 * The lock value is "<time>:<owner token>", so only the request that took the lock releases it.
	 *
	 * @return string The owner token, or '' when the lock was not obtained.
	 */
	private static function lock( $lock_option ) {
		$token = wp_generate_password( 12, false );
		for ( $try = 0; $try < self::WAIT_TRIES; $try++ ) {
			// add_option() trusts these caches when deciding whether the option exists.
			wp_cache_delete( $lock_option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( add_option( $lock_option, self::lock_value( $token ), '', false ) ) {
				return $token;
			}
			$taken = (string) get_option( $lock_option );
			if ( '' !== $taken && time() - self::lock_part( $taken, 0 ) > self::TTL ) {
				// Only remove the stale lock that was read, not a fresh one taken since.
				wp_cache_delete( $lock_option, 'options' );
				if ( (string) get_option( $lock_option ) === $taken ) {
					delete_option( $lock_option );
				}
				continue;
			}
			usleep( self::WAIT_USEC );
		}
		return '';
	}

	private static function lock_value( $token ) {
		return time() . ':' . $token;
	}

	/**
	 * Part 0 of a lock value is the time it was taken (int), part 1 the owner token (string).
	 */
	private static function lock_part( $value, $part ) {
		$parts = explode( ':', (string) $value, 2 );
		if ( 0 === $part ) {
			return (int) $parts[0];
		}
		return isset( $parts[1] ) ? $parts[1] : '';
	}

	/**
	 * Releases the lock, unless it expired and another request took it over in the meantime.
	 */
	private static function unlock( $lock_option, $token ) {
		wp_cache_delete( $lock_option, 'options' );
		if ( self::lock_part( get_option( $lock_option ), 1 ) === $token ) {
			delete_option( $lock_option );
		}
	}
}
