<?php

defined( 'ABSPATH' ) || exit;

/**
 * Syncs the WooCommerce catalog to Yotpo through the Core API (V3), in the background.
 *
 * - Product external ID = WooCommerce product ID, the ID widgets, orders and the review export
 *   already use, so existing reviews stay attached. Variations are synced as variants under
 *   their parent (external ID = variation ID).
 * - Yotpo creates products implicitly from widgets and orders, so a create often answers
 *   409 "already exists": the product is then looked up by external ID and updated.
 * - Products that are deleted, trashed or no longer published are blocklisted in Yotpo
 *   (no review requests, hidden from widgets); disabled or deleted variations are marked discontinued.
 * - All API work runs in Action Scheduler jobs, never inside a page load.
 */
class Yotpo_Product_Sync {

	// The GROUP, META_*, STATE_OPTION and LOCK_OPTION values are also written out in uninstall.php, which runs
	// without this class loaded. Keep both in sync when one changes, or uninstall leaves data behind.
	const GROUP           = 'yotpo-product-sync';
	const BATCH_SIZE      = 20;
	const MAX_ATTEMPTS    = 3;
	const RETRY_BASE_DELAY    = 60; // Seconds before the first retry; doubles with each attempt.
	const RATE_LIMIT_MIN_DELAY = 5; // Seconds to wait at least after a 429, even if Retry-After is lower or missing.
	const META_PRODUCT_ID = '_yotpo_v3_product_id';
	const META_VARIANT_ID = '_yotpo_v3_variant_id';
	const META_PRODUCT_HASH = '_yotpo_v3_product_hash'; // Fingerprint of the product data Yotpo last accepted.
	const META_VARIANT_HASH = '_yotpo_v3_variant_hash';
	const META_BLOCKLISTED = '_yotpo_v3_blocklisted'; // 'blocked' or 'absent': what Yotpo was last told, until the product is published again.
	const STATE_OPTION    = 'wc_yotpo_product_sync';
	const PAUSE_TRANSIENT = 'wc_yotpo_product_sync_paused_until'; // Set after a 429: until then no job calls Yotpo.
	const LOCK_OPTION     = 'wc_yotpo_product_sync_lock'; // Short-lived lock around changes to the sync state.
	const LOCK_TTL        = 30; // Seconds after which a lock left behind by a killed job is taken over.

	const HOOK_PRODUCT    = 'wc_yotpo_sync_product';
	const HOOK_BATCH      = 'wc_yotpo_sync_product_batch';
	const HOOK_VARIANT_GONE = 'wc_yotpo_discontinue_variant';

	public static function init() {
		add_action( self::HOOK_PRODUCT, array( __CLASS__, 'run_product_job' ), 10, 3 );
		add_action( self::HOOK_BATCH, array( __CLASS__, 'run_batch_job' ), 10, 1 );
		add_action( self::HOOK_VARIANT_GONE, array( __CLASS__, 'run_variant_gone_job' ), 10, 3 );

		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'queue_variation_parent' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_variation_parent' ) );
		add_action( 'trashed_post', array( __CLASS__, 'queue_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'queue_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_deleted_post' ) );
		add_action( 'init', array( __CLASS__, 'maybe_start_initial_sync' ), 20 );
	}

	/**
	 * Sync runs when credentials are saved. Filterable as a kill switch.
	 */
	public static function enabled() {
		// The client is the only reader of the saved credentials: no client means they are missing.
		$ready = (bool) Yotpo_V3_Client::from_settings();
		return (bool) apply_filters( 'wc_yotpo_product_sync_enabled', $ready );
	}

	// ---------------------------------------------------------------------------------------
	// Scheduling
	// ---------------------------------------------------------------------------------------

	/**
	 * Starts the full catalog sync once per app key: after updating to this version, or after
	 * the merchant connects a different Yotpo account.
	 */
	public static function maybe_start_initial_sync() {
		if ( ! self::scheduler_ready() ) {
			return;
		}
		$client = Yotpo_V3_Client::from_settings();
		if ( ! $client ) {
			return;
		}
		$state = self::state();
		// A sync stopped by the off switch starts again once the switch is back on.
		if ( isset( $state['app_key'] ) && $state['app_key'] === $client->app_key() && ( ! isset( $state['status'] ) || 'stopped' !== $state['status'] ) ) {
			return;
		}
		self::start_full_sync();
	}

	/**
	 * @return true|WP_Error
	 */
	public static function start_full_sync() {
		if ( ! Yotpo_V3_Client::from_settings() ) {
			return new WP_Error( 'yotpo_no_credentials', 'Save the Yotpo app key and secret first.' );
		}
		if ( ! self::scheduler_ready() ) {
			return new WP_Error( 'yotpo_no_scheduler', 'Action Scheduler is not available.' );
		}
		if ( ! self::enabled() ) {
			return new WP_Error( 'yotpo_sync_disabled', 'Product sync is turned off (filter wc_yotpo_product_sync_enabled).' );
		}
		// Restarting while jobs are queued would reset the counters under them and could finish the new sync early.
		$current = self::status();
		if ( isset( $current['status'] ) && 'running' === $current['status'] && $current['queued'] > 0 ) {
			return new WP_Error( 'yotpo_sync_running', 'A full product sync is already running.' );
		}
		$client = Yotpo_V3_Client::from_settings();
		self::update_state(
			function () use ( $client ) {
				return array(
					'app_key'    => $client->app_key(),
					'status'     => 'running',
					'started'    => time(),
					'finished'   => 0,
					'synced'     => 0,
					'failed'     => 0,
					'total'      => 0,
					'last_error' => '',
				);
			}
		);
		as_enqueue_async_action( self::HOOK_BATCH, array( 1 ), self::GROUP, true );
		ytdbg( '', 'Product sync: full sync queued' );
		return true;
	}

	/**
	 * @param bool $full_sync True for a job of the full catalog sync: only those are counted in the sync state.
	 * @return int The queued action ID (0 when nothing was queued, for example a duplicate).
	 */
	public static function queue_product( $product_id, $attempt = 1, $delay = 0, $full_sync = false ) {
		if ( ! self::scheduler_ready() ) {
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
		if ( ! self::scheduler_ready() ) {
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
	 * product job has finished (see count_result()).
	 */
	public static function run_batch_job( $page ) {
		// The off switch also stops jobs that were queued before it was turned on.
		if ( ! self::enabled() ) {
			self::stop_full_sync();
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
		self::update_state(
			function ( $state ) use ( $queued, $more ) {
				$state['total'] = ( isset( $state['total'] ) ? (int) $state['total'] : 0 ) + $queued;
				if ( ! $more ) {
					$state['pages_done'] = true;
				}
				return self::finish_if_done( $state );
			}
		);
	}

	/**
	 * One product, queued by a change in WooCommerce or by the full sync. Retries with growing delays.
	 * Only the full sync's jobs count towards its progress.
	 */
	public static function run_product_job( $product_id, $attempt = 1, $full_sync = 0 ) {
		if ( ! self::enabled() ) {
			if ( $full_sync ) {
				self::stop_full_sync();
			}
			return;
		}
		// Yotpo asked every caller to slow down: wait without spending an attempt.
		$paused_for = self::paused_for();
		if ( $paused_for > 0 ) {
			self::queue_product( $product_id, $attempt, $paused_for, (bool) $full_sync );
			return;
		}
		$result = self::sync_product( $product_id, (bool) $full_sync );
		self::pause_if_rate_limited( $result, $attempt );
		if ( is_wp_error( $result ) && self::is_retryable( $result ) && $attempt < self::MAX_ATTEMPTS ) {
			self::queue_product( $product_id, $attempt + 1, self::retry_delay( $result, $attempt ), (bool) $full_sync );
			return;
		}
		if ( $full_sync ) {
			self::count_result( $result );
		}
	}

	public static function run_variant_gone_job( $variation_id, $parent_id, $attempt = 1 ) {
		if ( ! self::enabled() ) {
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
		$error = self::discontinue_variant( $client, $variation_id, $parent_id );
		self::pause_if_rate_limited( $error, $attempt );
		if ( ! $error ) {
			return;
		}
		if ( self::is_retryable( $error ) && $attempt < self::MAX_ATTEMPTS ) {
			self::queue_variant_gone( $variation_id, $parent_id, $attempt + 1, self::retry_delay( $error, $attempt ) );
			return;
		}
		ytdbg( $error->get_error_message(), 'Product sync: variation #' . $variation_id . ' not discontinued:' );
	}

	/**
	 * Marks a deleted variation as discontinued in Yotpo. Nothing to do if Yotpo never had it.
	 *
	 * @return WP_Error|null The first error of the lookups or the update.
	 */
	private static function discontinue_variant( $client, $variation_id, $parent_id ) {
		$yotpo_product_id = self::find_yotpo_product_id( $client, $parent_id );
		if ( is_wp_error( $yotpo_product_id ) ) {
			return $yotpo_product_id;
		}
		if ( ! $yotpo_product_id ) {
			return null;
		}
		$yotpo_variant_id = self::find_yotpo_variant_id( $client, $yotpo_product_id, $variation_id );
		if ( is_wp_error( $yotpo_variant_id ) ) {
			return $yotpo_variant_id;
		}
		if ( ! $yotpo_variant_id ) {
			return null;
		}
		$response = $client->request( 'PATCH', '/products/' . $yotpo_product_id . '/variants/' . $yotpo_variant_id, array( 'variant' => array( 'is_discontinued' => true ) ) );
		return self::response_error( $response );
	}

	// ---------------------------------------------------------------------------------------
	// Sync
	// ---------------------------------------------------------------------------------------

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
			return self::blocklist_product( $client, $product_id, $force );
		}

		$yotpo_id = self::upsert_product( $client, $product, $force );
		if ( is_wp_error( $yotpo_id ) ) {
			ytdbg( $yotpo_id->get_error_message(), 'Product sync: product #' . $product_id . ' failed:' );
			return $yotpo_id;
		}
		delete_post_meta( $product_id, self::META_BLOCKLISTED );

		if ( $product->is_type( 'variable' ) ) {
			$first_error = null;
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$variant = self::upsert_variant( $client, $yotpo_id, $variation, $product, $force );
				if ( is_wp_error( $variant ) ) {
					ytdbg( $variant->get_error_message(), 'Product sync: variation #' . $variation_id . ' failed:' );
					if ( self::is_retryable( $variant ) ) {
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

	private static function upsert_product( $client, $product, $force = false ) {
		return self::upsert( $client, self::product_spec(), $product->get_id(), self::product_payload( $product ), $force );
	}

	private static function upsert_variant( $client, $yotpo_product_id, $variation, $parent, $force = false ) {
		return self::upsert( $client, self::variant_spec( $yotpo_product_id ), $variation->get_id(), self::variant_payload( $variation, $parent ), $force );
	}

	/**
	 * What differs between products and variants in the Yotpo API:
	 * - path:     the collection path.
	 * - key:      the key the object travels under.
	 * - list_key: the key of the list in a lookup answer.
	 * - meta:     the post meta that remembers the Yotpo ID.
	 * - hash:     the post meta that remembers the fingerprint of the data Yotpo last accepted;
	 *             upsert() skips a request when the data still matches it.
	 * - salt:     text added to the fingerprint for anything else the accepted data depends on
	 *             (a variant is only up to date under the Yotpo product it was sent to; empty for products).
	 */
	private static function product_spec() {
		return array(
			'path'     => '/products',
			'key'      => 'product',
			'list_key' => 'products',
			'meta'     => self::META_PRODUCT_ID,
			'hash'     => self::META_PRODUCT_HASH,
			'salt'     => '',
		);
	}

	private static function variant_spec( $yotpo_product_id ) {
		return array(
			'path'     => '/products/' . $yotpo_product_id . '/variants',
			'key'      => 'variant',
			'list_key' => 'variants',
			'meta'     => self::META_VARIANT_ID,
			'hash'     => self::META_VARIANT_HASH,
			'salt'     => (string) $yotpo_product_id, // A variant is only up to date under the Yotpo product it was sent to.
		);
	}

	/**
	 * Creates or updates one product or variant in Yotpo (see product_spec() and variant_spec()).
	 * A product or variant whose data is the same as the last accepted is not sent again, unless $force.
	 *
	 * @return int|WP_Error The Yotpo ID.
	 */
	private static function upsert( $client, $spec, $post_id, $payload, $force = false ) {
		$hash     = md5( $spec['salt'] . wp_json_encode( $payload ) );
		$yotpo_id = (int) get_post_meta( $post_id, $spec['meta'], true );
		if ( $yotpo_id && ! $force && get_post_meta( $post_id, $spec['hash'], true ) === $hash ) {
			return $yotpo_id;
		}

		$result = self::send_upsert( $client, $spec, $post_id, $payload );
		if ( is_wp_error( $result ) ) {
			delete_post_meta( $post_id, $spec['hash'] );
		} else {
			update_post_meta( $post_id, $spec['hash'], $hash );
		}
		return $result;
	}

	private static function send_upsert( $client, $spec, $post_id, $payload ) {
		$yotpo_id = (int) get_post_meta( $post_id, $spec['meta'], true );

		if ( $yotpo_id ) {
			$error = self::patch( $client, $spec, $yotpo_id, $payload );
			if ( ! $error ) {
				return $yotpo_id;
			}
			if ( self::is_retryable( $error ) ) {
				return $error;
			}
			// Rejected: the stored ID may be stale (deleted in Yotpo, or an ID that isn't this
			// store's). Look it up again by external ID before deciding.
			delete_post_meta( $post_id, $spec['meta'] );
			$found = self::find_yotpo_id( $client, $spec, $post_id );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			if ( $found === $yotpo_id ) {
				return $error; // The ID was right; Yotpo rejected the data itself.
			}
			if ( $found ) {
				$error = self::patch( $client, $spec, $found, $payload );
				return $error ? $error : $found;
			}
			// Not in Yotpo any more: create it again below.
		}

		$response = $client->request( 'POST', $spec['path'], array( $spec['key'] => array_merge( array( 'external_id' => (string) $post_id ), $payload ) ) );
		if ( 201 === $response['code'] || 200 === $response['code'] ) {
			$yotpo_id = isset( $response['body'][ $spec['key'] ]['yotpo_id'] ) ? (int) $response['body'][ $spec['key'] ]['yotpo_id'] : 0;
			if ( ! $yotpo_id ) {
				return new WP_Error( 'yotpo_bad_response', 'Create ' . $spec['key'] . ' returned no yotpo_id' );
			}
			update_post_meta( $post_id, $spec['meta'], $yotpo_id );
			return $yotpo_id;
		}
		if ( 409 !== $response['code'] ) {
			return self::response_error( $response );
		}

		// Already in Yotpo (often created from widgets or orders): find it and update it.
		$yotpo_id = self::find_yotpo_id( $client, $spec, $post_id );
		if ( is_wp_error( $yotpo_id ) ) {
			return $yotpo_id;
		}
		if ( ! $yotpo_id ) {
			return new WP_Error( 'yotpo_not_found', 'Create ' . $spec['key'] . ' answered "already exists" but the lookup found nothing' );
		}
		$error = self::patch( $client, $spec, $yotpo_id, $payload );
		return $error ? $error : $yotpo_id;
	}

	/**
	 * Updates one product or variant by its Yotpo ID.
	 *
	 * @return WP_Error|null Null when Yotpo accepted it.
	 */
	private static function patch( $client, $spec, $yotpo_id, $payload ) {
		return self::response_error( $client->request( 'PATCH', $spec['path'] . '/' . $yotpo_id, array( $spec['key'] => $payload ) ) );
	}

	/**
	 * Blocklists a product that is gone or not published. Nothing to do if Yotpo never had it.
	 * The outcome is remembered in post meta, so saving a draft again costs no API call.
	 */
	private static function blocklist_product( $client, $product_id, $force = false ) {
		if ( ! $force && get_post_meta( $product_id, self::META_BLOCKLISTED, true ) ) {
			return 0;
		}
		$spec     = self::product_spec();
		$cached   = (int) get_post_meta( $product_id, self::META_PRODUCT_ID, true );
		$yotpo_id = self::find_yotpo_id( $client, $spec, $product_id );
		if ( is_wp_error( $yotpo_id ) ) {
			return $yotpo_id;
		}
		if ( ! $yotpo_id ) {
			self::remember_blocklist( $product_id, 'absent' );
			return 0;
		}
		$payload = array( 'custom_properties' => array( 'is_blocklisted' => true ) );
		$error   = self::patch( $client, $spec, $yotpo_id, $payload );
		if ( $error && $cached && ! self::is_retryable( $error ) ) {
			// Rejected: the stored ID may be stale. Look it up again by external ID, as upsert() does.
			delete_post_meta( $product_id, self::META_PRODUCT_ID );
			$found = self::find_yotpo_id( $client, $spec, $product_id );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			if ( ! $found ) {
				self::remember_blocklist( $product_id, 'absent' );
				return 0; // Yotpo no longer has it: nothing to blocklist.
			}
			if ( $found !== $yotpo_id ) {
				$yotpo_id = $found;
				$error    = self::patch( $client, $spec, $yotpo_id, $payload );
			}
		}
		if ( $error ) {
			return $error;
		}
		self::remember_blocklist( $product_id, 'blocked' );
		ytdbg( 'Yotpo ID ' . $yotpo_id, 'Product sync: product #' . $product_id . ' blocklisted,' );
		return $yotpo_id;
	}

	private static function remember_blocklist( $product_id, $outcome ) {
		if ( get_post_status( $product_id ) ) { // A deleted product has no meta to keep.
			update_post_meta( $product_id, self::META_BLOCKLISTED, $outcome );
			// Yotpo no longer has the data last sent; publishing again must send it.
			delete_post_meta( $product_id, self::META_PRODUCT_HASH );
		}
	}

	/**
	 * Yotpo ID from post meta, else from the API by external ID (0 when Yotpo doesn't have it).
	 *
	 * @return int|WP_Error
	 */
	private static function find_yotpo_id( $client, $spec, $post_id ) {
		$cached = (int) get_post_meta( $post_id, $spec['meta'], true );
		if ( $cached ) {
			return $cached;
		}
		$response = $client->request( 'GET', $spec['path'], null, array( 'external_ids' => (string) $post_id ) );
		$error    = self::response_error( $response );
		if ( $error ) {
			return $error;
		}
		foreach ( isset( $response['body'][ $spec['list_key'] ] ) ? (array) $response['body'][ $spec['list_key'] ] : array() as $found ) {
			if ( isset( $found['external_id'], $found['yotpo_id'] ) && (string) $found['external_id'] === (string) $post_id ) {
				if ( get_post_status( $post_id ) ) {
					update_post_meta( $post_id, $spec['meta'], (int) $found['yotpo_id'] );
				}
				return (int) $found['yotpo_id'];
			}
		}
		return 0;
	}

	private static function find_yotpo_product_id( $client, $product_id ) {
		return self::find_yotpo_id( $client, self::product_spec(), $product_id );
	}

	private static function find_yotpo_variant_id( $client, $yotpo_product_id, $variation_id ) {
		return self::find_yotpo_id( $client, self::variant_spec( $yotpo_product_id ), $variation_id );
	}

	// ---------------------------------------------------------------------------------------
	// Payloads
	// ---------------------------------------------------------------------------------------

	/**
	 * Product fields for create and update. Same values the widgets send today.
	 */
	public static function product_payload( $product ) {
		$payload = array(
			'name'              => self::limit( $product->get_name(), 255 ),
			'description'       => wp_strip_all_tags( $product->get_description() ),
			'status'            => 'active',
			'url'               => self::limit( get_permalink( $product->get_id() ), 2048 ),
			'currency'          => get_woocommerce_currency(),
			'custom_properties' => array( 'is_blocklisted' => false ),
		);
		$image = wc_yotpo_get_product_image_url( $product->get_id() );
		if ( $image ) {
			$payload['image_url'] = self::limit( $image, 2048 );
		}
		if ( '' !== (string) $product->get_price() ) {
			$payload['price'] = (float) $product->get_price();
		}
		if ( $product->managing_stock() && null !== $product->get_stock_quantity() ) {
			$payload['inventory_quantity'] = (int) $product->get_stock_quantity();
		}
		$sku = self::valid_sku( $product->get_sku() );
		if ( $sku ) {
			$payload['sku'] = $sku;
		}
		foreach ( array( 'brand', 'mpn' ) as $field ) {
			$value = trim( (string) $product->get_attribute( $field ) );
			if ( '' !== $value ) {
				$payload[ $field ] = self::limit( $value, 255 );
			}
		}
		$gtins = self::gtins( $product );
		if ( $gtins ) {
			$payload['gtins'] = $gtins;
		}
		/**
		 * Filters the product fields sent to Yotpo (for example, to point image URLs at a CDN).
		 * The external ID is added separately and can't be changed here.
		 */
		return (array) apply_filters( 'wc_yotpo_product_sync_product_payload', $payload, $product );
	}

	public static function variant_payload( $variation, $parent ) {
		$payload = array(
			'name'            => self::limit( $variation->get_name(), 255 ),
			'url'             => self::limit( $variation->get_permalink(), 2048 ),
			'currency'        => get_woocommerce_currency(),
			// A disabled variation ("private") gets no review requests and is hidden from widgets.
			'is_discontinued' => 'publish' !== $variation->get_status(),
		);
		$image_id = $variation->get_image_id() ? $variation->get_image_id() : $parent->get_image_id();
		$image    = $image_id ? wp_get_attachment_url( $image_id ) : '';
		if ( $image ) {
			$payload['image_url'] = self::limit( $image, 2048 );
		}
		if ( '' !== (string) $variation->get_price() ) {
			$payload['price'] = (float) $variation->get_price();
		}
		if ( $variation->managing_stock() && null !== $variation->get_stock_quantity() ) {
			$payload['inventory_quantity'] = (int) $variation->get_stock_quantity();
		}
		$sku = self::valid_sku( $variation->get_sku() );
		if ( $sku && $sku !== self::valid_sku( $parent->get_sku() ) ) {
			// A variation without its own SKU inherits the parent's; don't send that as the variant's.
			$payload['sku'] = $sku;
		}
		$options = array();
		foreach ( $variation->get_variation_attributes( false ) as $taxonomy => $value ) {
			if ( '' === (string) $value ) {
				continue; // "Any" value: not a fixed option.
			}
			$name = wc_attribute_label( $taxonomy, $parent );
			if ( taxonomy_exists( $taxonomy ) ) {
				$term  = get_term_by( 'slug', $value, $taxonomy );
				$value = $term ? $term->name : $value;
			}
			$options[] = array( 'name' => (string) $name, 'value' => (string) $value );
		}
		if ( $options ) {
			$payload['options'] = array_slice( $options, 0, 20 );
		}
		/** Filters the variant fields sent to Yotpo. */
		return (array) apply_filters( 'wc_yotpo_product_sync_variant_payload', $payload, $variation, $parent );
	}

	private static function gtins( $product ) {
		$gtins = array();
		foreach ( array( 'upc' => 'UPC', 'isbn' => 'ISBN', 'ean' => 'EAN' ) as $attribute => $type ) {
			$value = trim( (string) $product->get_attribute( $attribute ) );
			if ( '' !== $value ) {
				$gtins[] = array( 'declared_type' => $type, 'value' => $value );
			}
		}
		return $gtins;
	}

	/**
	 * Yotpo rejects SKUs with spaces or "/"; such a SKU is left out rather than failing the product.
	 */
	private static function valid_sku( $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku || preg_match( '#[\s/]#', $sku ) ) {
			return '';
		}
		return self::limit( $sku, 255 );
	}

	private static function limit( $text, $max ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	// ---------------------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------------------

	/**
	 * Maps a response to a WP_Error, or null when it succeeded.
	 */
	private static function response_error( $response ) {
		if ( $response['code'] >= 200 && $response['code'] < 300 ) {
			return null;
		}
		if ( 429 === $response['code'] ) {
			return new WP_Error( 'yotpo_rate_limited', 'Yotpo rate limit reached', $response['retry_after'] );
		}
		$code = 0 === $response['code'] ? 'yotpo_network' : ( $response['code'] >= 500 ? 'yotpo_server' : 'yotpo_rejected' );
		return new WP_Error( $code, $response['error'] ? $response['error'] : 'HTTP ' . $response['code'] );
	}

	/**
	 * Network, server and rate-limit errors are worth retrying; a 4xx rejection is not.
	 */
	private static function is_retryable( $error ) {
		return in_array( $error->get_error_code(), array( 'yotpo_rate_limited', 'yotpo_network', 'yotpo_server' ), true );
	}

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

	private static function count_result( $result ) {
		self::update_state(
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
	private static function stop_full_sync() {
		self::update_state(
			function ( $state ) {
				if ( isset( $state['status'] ) && 'running' === $state['status'] ) {
					$state['status'] = 'stopped';
					return $state;
				}
				return null;
			}
		);
	}

	/**
	 * Marks a running full sync complete once every page is queued and every queued product job finished.
	 */
	private static function finish_if_done( $state ) {
		if ( isset( $state['status'] ) && 'running' === $state['status'] && ! empty( $state['pages_done'] )
			&& (int) $state['synced'] + (int) $state['failed'] + ( isset( $state['skipped'] ) ? (int) $state['skipped'] : 0 ) >= (int) $state['total'] ) {
			$state['status']   = 'complete';
			$state['finished'] = time();
			ytdbg( $state['synced'] . ' synced, ' . $state['failed'] . ' failed', 'Product sync: full sync done:' );
		}
		return $state;
	}

	/**
	 * The full sync state plus the number of sync jobs still queued.
	 */
	public static function status() {
		$status           = self::state();
		$status['queued'] = 0;
		if ( self::scheduler_ready() && class_exists( 'ActionScheduler' ) ) {
			$status['queued'] = (int) ActionScheduler::store()->query_actions(
				array(
					'group'  => self::GROUP,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				),
				'count'
			);
		}
		return $status;
	}

	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Changes the sync state without losing a concurrent change: product jobs of one full sync can
	 * run in parallel, and each would otherwise overwrite the counters the others just saved.
	 *
	 * @param callable $change Receives the current state; returns the state to save, or null to save nothing.
	 */
	private static function update_state( $change ) {
		$locked = self::lock_state();
		// Another runner may have saved since this request loaded its options.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::STATE_OPTION, 'options' );
		$new_state = call_user_func( $change, self::state() );
		if ( is_array( $new_state ) ) {
			self::save_state( $new_state );
		}
		if ( $locked ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Takes the state lock (an option that add_option() only creates once). Waits up to five seconds;
	 * a lock older than LOCK_TTL was left by a killed job and is taken over. Best effort: after
	 * the wait the change goes ahead without the lock rather than being lost.
	 *
	 * @return bool Whether this request now holds the lock.
	 */
	private static function lock_state() {
		for ( $try = 0; $try < 50; $try++ ) {
			// add_option() trusts these caches when deciding whether the option exists.
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
				return true;
			}
			$taken = (int) get_option( self::LOCK_OPTION );
			if ( $taken && time() - $taken > self::LOCK_TTL ) {
				delete_option( self::LOCK_OPTION );
				continue;
			}
			usleep( 100000 );
		}
		return false;
	}

	private static function save_state( $state ) {
		// Autoloaded: maybe_start_initial_sync() reads it on every request.
		update_option( self::STATE_OPTION, $state, true );
	}

	private static function scheduler_ready() {
		return function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' );
	}
}
