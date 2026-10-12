<?php

defined( 'ABSPATH' ) || exit;

/**
 * Yotpo product sync from WP-CLI, for support and testing.
 *
 *     wp yotpo product-sync status
 *     wp yotpo product-sync start
 *     wp yotpo product-sync product <id>
 */
class Yotpo_Sync_CLI {

	/**
	 * Shows the state of the full catalog sync and the jobs still queued.
	 *
	 * @subcommand status
	 */
	public function status() {
		$state = Yotpo_Product_Sync::state();
		if ( empty( $state ) ) {
			WP_CLI::log( 'No product sync has run yet.' );
			return;
		}
		foreach ( array( 'status', 'synced', 'failed', 'last_error' ) as $key ) {
			WP_CLI::log( sprintf( '%-11s %s', $key . ':', isset( $state[ $key ] ) ? $state[ $key ] : '' ) );
		}
		foreach ( array( 'started', 'finished', 'last_activity' ) as $key ) {
			if ( ! empty( $state[ $key ] ) ) {
				WP_CLI::log( sprintf( '%-11s %s UTC', $key . ':', gmdate( 'Y-m-d H:i:s', $state[ $key ] ) ) );
			}
		}
		$pending = as_get_scheduled_actions( array( 'group' => Yotpo_Product_Sync::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' );
		WP_CLI::log( sprintf( '%-11s %d', 'queued:', count( $pending ) ) );
	}

	/**
	 * Queues a full catalog sync in the background.
	 *
	 * @subcommand start
	 */
	public function start() {
		if ( ! Yotpo_V3_Client::from_settings() ) {
			WP_CLI::error( 'Save the Yotpo app key and secret first.' );
		}
		Yotpo_Product_Sync::start_full_sync() ? WP_CLI::success( 'Full product sync queued.' ) : WP_CLI::error( 'Action Scheduler is not available.' );
	}

	/**
	 * Syncs one product now, in the foreground.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : WooCommerce product ID.
	 *
	 * @subcommand product
	 */
	public function product( $args ) {
		$result = Yotpo_Product_Sync::sync_product( (int) $args[0], true );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_code() . ': ' . $result->get_error_message() );
		}
		WP_CLI::success( $result ? 'Synced. Yotpo product ID: ' . $result : 'Nothing to sync (not in Yotpo and not published).' );
	}
}
