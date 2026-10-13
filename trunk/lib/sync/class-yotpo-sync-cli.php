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
	 * Shows the state of the full catalog sync and the sync jobs queued or running.
	 *
	 * @subcommand status
	 */
	public function status() {
		$state = Yotpo_Product_Sync::status();
		if ( empty( $state['status'] ) ) {
			WP_CLI::log( 'No product sync has run yet.' );
			return;
		}
		foreach ( array( 'status', 'total', 'synced', 'failed', 'skipped', 'last_error', 'queued', 'in_progress' ) as $key ) {
			WP_CLI::log( sprintf( '%-14s %s', $key . ':', isset( $state[ $key ] ) ? $state[ $key ] : '' ) );
		}
		foreach ( array( 'started', 'finished', 'last_activity' ) as $key ) {
			if ( ! empty( $state[ $key ] ) ) {
				WP_CLI::log( sprintf( '%-14s %s UTC', $key . ':', gmdate( 'Y-m-d H:i:s', $state[ $key ] ) ) );
			}
		}
	}

	/**
	 * Queues a full catalog sync in the background.
	 *
	 * @subcommand start
	 */
	public function start() {
		$result = Yotpo_Product_Sync::start_full_sync();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( 'Full product sync queued.' );
	}

	/**
	 * Syncs one product now, in the foreground. Runs even when the sync is turned off with the
	 * wc_yotpo_product_sync_enabled filter, so support can test a single product.
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
