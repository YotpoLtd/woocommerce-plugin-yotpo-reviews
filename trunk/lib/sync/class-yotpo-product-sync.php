<?php

defined( 'ABSPATH' ) || exit;

/**
 * Syncs the WooCommerce catalog to Yotpo through the Core API (V3), in the background.
 * Entry point of the sync: wiring, the kill switch, and the sync of one product.
 *
 * - Product external ID = WooCommerce product ID, the ID widgets, orders and the review export
 *   already use, so existing reviews stay attached. Variations are synced as variants under
 *   their parent (external ID = variation ID).
 * - Products that are deleted, trashed or no longer published are blocklisted in Yotpo
 *   (no review requests, hidden from widgets); disabled or deleted variations are marked discontinued.
 * - All API work runs in Action Scheduler jobs, never inside a page load.
 *
 * The rest lives in:
 * - Yotpo_Sync_Jobs:       WooCommerce hooks, job queueing and runners, retries, the full sync.
 * - Yotpo_Sync_State:      the full sync's state and counters.
 * - Yotpo_Catalog_Api:     products and variants in the Yotpo catalog (create, update, look up, blocklist).
 * - Yotpo_Product_Payload: the WooCommerce → Yotpo field mapping.
 */
class Yotpo_Product_Sync {

	public static function init() {
		Yotpo_Sync_Jobs::register();
	}

	/**
	 * Sync runs when credentials are saved and the merchant left "Sync products to Yotpo" on in the
	 * settings. Also filterable as a kill switch (wc_yotpo_product_sync_enabled).
	 * Checked wherever a job is queued or run, and by start_full_sync(). The only way around it is
	 * calling sync_product() directly, as `wp yotpo product-sync product` does on purpose.
	 */
	public static function enabled() {
		// The client is the only reader of the saved credentials: no client means they are missing.
		$ready = Yotpo_V3_Client::from_settings() && self::setting_on();
		return (bool) apply_filters( 'wc_yotpo_product_sync_enabled', $ready );
	}

	/**
	 * The merchant's "Sync products to Yotpo" setting. On by default, including for settings saved
	 * before the setting existed.
	 */
	public static function setting_on() {
		$settings = get_option( 'yotpo_settings', array() );
		return ! is_array( $settings ) || ! array_key_exists( 'product_sync_enabled', $settings ) || (bool) $settings['product_sync_enabled'];
	}

	/**
	 * Called after the settings are saved: turning the sync off cancels what is queued, turning it
	 * back on starts a full sync to catch up with changes made while it was off.
	 */
	public static function setting_changed( $was_on, $is_on ) {
		if ( $was_on && ! $is_on ) {
			Yotpo_Sync_Jobs::cancel_all();
			Yotpo_Sync_State::stop();
		} elseif ( ! $was_on && $is_on ) {
			Yotpo_Sync_Jobs::start_full_sync();
		}
	}

	/**
	 * Queues a full catalog sync.
	 *
	 * @return true|WP_Error
	 */
	public static function start_full_sync() {
		return Yotpo_Sync_Jobs::start_full_sync();
	}

	/**
	 * Creates or updates one product and its variants in Yotpo, or blocklists it.
	 *
	 * @param int  $product_id WooCommerce product ID. A variation ID is accepted and syncs its parent product.
	 * @param bool $force      Also redo a product that is already known to be blocklisted or missing in Yotpo.
	 * @return int|WP_Error The Yotpo product ID (0 when there was nothing to do).
	 */
	public static function sync_product( $product_id, $force = false ) {
		$client = Yotpo_V3_Client::from_settings();
		if ( ! $client ) {
			return new WP_Error( 'yotpo_no_credentials', 'App key or secret missing' );
		}

		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			// Work with the parent from here on, including for the blocklist lookup and the logs.
			$parent_id = $product->get_parent_id();
			$product   = $parent_id ? wc_get_product( $parent_id ) : false;
			if ( $parent_id ) {
				$product_id = $parent_id;
			}
		}
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return Yotpo_Catalog_Api::blocklist_product( $client, $product_id, $force );
		}

		$yotpo_id = Yotpo_Catalog_Api::upsert_product( $client, $product, $force );
		if ( is_wp_error( $yotpo_id ) ) {
			ytdbg( $yotpo_id->get_error_message(), 'Product sync: product #' . $product_id . ' failed:' );
			return $yotpo_id;
		}
		Yotpo_Catalog_Api::forget_blocklist( $product_id );

		if ( $product->is_type( 'variable' ) ) {
			$first_error = null;
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$variant = Yotpo_Catalog_Api::upsert_variant( $client, $yotpo_id, $variation, $product, $force );
				if ( is_wp_error( $variant ) ) {
					ytdbg( $variant->get_error_message(), 'Product sync: variation #' . $variation_id . ' failed:' );
					if ( Yotpo_Catalog_Api::is_retryable( $variant ) ) {
						return $variant; // The retry runs the whole product again.
					}
					// A variation Yotpo rejected for good must not block the others.
					if ( ! $first_error ) {
						$first_error = $variant;
					}
				}
			}
			if ( $first_error ) {
				return $first_error;
			}
		}

		ytdbg( 'Yotpo ID ' . $yotpo_id, 'Product sync: product #' . $product_id . ' synced,' );
		return $yotpo_id;
	}

	/**
	 * Product fields sent to Yotpo (filter: wc_yotpo_product_sync_product_payload).
	 */
	public static function product_payload( $product ) {
		return Yotpo_Product_Payload::product( $product );
	}

	/**
	 * Variant fields sent to Yotpo (filter: wc_yotpo_product_sync_variant_payload).
	 */
	public static function variant_payload( $variation, $parent ) {
		return Yotpo_Product_Payload::variant( $variation, $parent );
	}

	/**
	 * The full sync state plus the number of sync jobs queued and running.
	 */
	public static function status() {
		return array_merge( Yotpo_Sync_State::get(), Yotpo_Sync_Jobs::queue_counts() );
	}

	public static function state() {
		return Yotpo_Sync_State::get();
	}

	/**
	 * One line for the settings page: what the sync is doing or last did.
	 */
	public static function status_summary() {
		if ( ! self::setting_on() ) {
			return 'Off. Products are not sent to Yotpo.';
		}
		if ( ! Yotpo_V3_Client::from_settings() ) {
			return 'Starts once the app key and secret token are saved.';
		}
		if ( ! self::enabled() ) {
			return 'Turned off by the wc_yotpo_product_sync_enabled filter.';
		}
		$status = self::status();
		$synced = isset( $status['synced'] ) ? (int) $status['synced'] : 0;
		$failed = isset( $status['failed'] ) ? (int) $status['failed'] : 0;
		switch ( isset( $status['status'] ) ? $status['status'] : '' ) {
			case Yotpo_Sync_State::STATUS_RUNNING:
				$done = $synced + $failed + ( isset( $status['skipped'] ) ? (int) $status['skipped'] : 0 );
				return sprintf( 'Full sync running: %d of %d products done.', $done, isset( $status['total'] ) ? (int) $status['total'] : 0 );
			case Yotpo_Sync_State::STATUS_COMPLETE:
				$line = sprintf( 'Last full sync: %s. %d synced, %d failed.', wp_date( 'j M Y, H:i', (int) $status['finished'] ), $synced, $failed );
				if ( $failed && ! empty( $status['last_error'] ) ) {
					$line .= ' Last error: ' . $status['last_error'];
				}
				return $line;
			case Yotpo_Sync_State::STATUS_STOPPED:
				return 'Full sync stopped. It starts again on the next page load.';
			case Yotpo_Sync_State::STATUS_STALLED:
				return sprintf( 'Full sync stalled: %d of %d products done. %s', $synced + $failed + ( isset( $status['skipped'] ) ? (int) $status['skipped'] : 0 ), isset( $status['total'] ) ? (int) $status['total'] : 0, isset( $status['last_error'] ) ? $status['last_error'] : '' );
			default:
				return 'Full sync starts on the next page load.';
		}
	}
}
