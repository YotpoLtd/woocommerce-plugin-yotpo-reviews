<?php

defined( 'ABSPATH' ) || exit;

/**
 * Products and variants in the Yotpo catalog (Core API V3): create-or-update, lookups by external ID,
 * blocklisting and discontinuing. Remembers Yotpo IDs and data fingerprints in post meta.
 *
 * - Yotpo creates products implicitly from widgets and orders, so a create often answers
 *   409 "already exists": the product is then looked up by external ID and updated.
 * - A stored Yotpo ID that Yotpo rejects is re-checked by external ID before anything is created.
 */
class Yotpo_Catalog_Api {

	// Also written out in uninstall.php, which runs without this class loaded: keep both in sync.
	const META_PRODUCT_ID   = '_yotpo_v3_product_id';
	const META_VARIANT_ID   = '_yotpo_v3_variant_id';
	const META_PRODUCT_HASH = '_yotpo_v3_product_hash'; // Fingerprint of the product data Yotpo last accepted, or was found to have (compare() also sets it).
	const META_VARIANT_HASH = '_yotpo_v3_variant_hash'; // The same for a variant.
	const META_BLOCKLISTED  = '_yotpo_v3_blocklisted'; // Set ('1') once Yotpo has been told, or found to be without the product, until it is published again.

	public static function upsert_product( $client, $product, $force = false ) {
		return self::upsert( $client, self::product_spec(), $product->get_id(), Yotpo_Product_Payload::product( $product ), $force );
	}

	public static function upsert_variant( $client, $yotpo_product_id, $variation, $parent, $force = false ) {
		return self::upsert( $client, self::variant_spec( $yotpo_product_id ), $variation->get_id(), Yotpo_Product_Payload::variant( $variation, $parent ), $force );
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
	 * "Last accepted" includes what compare() found Yotpo to have: it sets the same ID and fingerprint post meta.
	 *
	 * @return int|WP_Error The Yotpo ID.
	 */
	private static function upsert( $client, $spec, $post_id, $payload, $force = false ) {
		$hash     = self::fingerprint( $spec, $payload );
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

	/**
	 * @param bool $create_only Leave a product or variant Yotpo turns out to have as it is (create_product()).
	 */
	private static function send_upsert( $client, $spec, $post_id, $payload, $create_only = false ) {
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
			return Yotpo_V3_Client::response_error( $response );
		}

		// Already in Yotpo (often created from widgets or orders): find it and update it.
		$yotpo_id = self::find_yotpo_id( $client, $spec, $post_id );
		if ( is_wp_error( $yotpo_id ) ) {
			return $yotpo_id;
		}
		if ( ! $yotpo_id ) {
			return new WP_Error( 'yotpo_not_found', 'Create ' . $spec['key'] . ' answered "already exists" but the lookup found nothing' );
		}
		if ( $create_only ) {
			return $yotpo_id;
		}
		$error = self::patch( $client, $spec, $yotpo_id, $payload );
		return $error ? $error : $yotpo_id;
	}

	private static function fingerprint( $spec, $payload ) {
		return md5( $spec['salt'] . wp_json_encode( $payload ) );
	}

	/**
	 * Creates a product Yotpo lacks, and changes nothing Yotpo has: one created in Yotpo since the
	 * caller's lookup (the create answers 409) is found and its ID stored, not updated. Does not
	 * store the data's fingerprint, so the product sync still compares the product with Yotpo.
	 *
	 * @param WC_Product $product
	 * @return int|WP_Error The Yotpo ID.
	 */
	public static function create_product( $client, $product ) {
		$stored = (int) get_post_meta( $product->get_id(), self::META_PRODUCT_ID, true );
		if ( $stored ) {
			return $stored;
		}
		return self::send_upsert( $client, self::product_spec(), $product->get_id(), Yotpo_Product_Payload::product( $product ), true );
	}

	/**
	 * Looks these products up in Yotpo, LOOKUP_MAX per request, and stores the Yotpo IDs found.
	 *
	 * @param int[] $product_ids WooCommerce product IDs.
	 * @return int[]|WP_Error The IDs of the products Yotpo has.
	 */
	public static function find_products( $client, array $product_ids ) {
		$spec  = self::product_spec();
		$found = array();
		foreach ( array_chunk( $product_ids, Yotpo_V3_Client::LOOKUP_MAX ) as $chunk ) {
			$items = $client->lookup_all( $spec, $chunk );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			$in_yotpo = Yotpo_V3_Client::index_by_external_id( $items );
			foreach ( $chunk as $product_id ) {
				if ( isset( $in_yotpo[ (string) $product_id ] ) ) {
					update_post_meta( $product_id, self::META_PRODUCT_ID, (int) $in_yotpo[ (string) $product_id ]['yotpo_id'] );
					$found[] = $product_id;
				}
			}
		}
		return $found;
	}

	// ---------------------------------------------------------------------------------------
	// Comparing with Yotpo before a full sync
	// ---------------------------------------------------------------------------------------

	/**
	 * Before the full sync sends a page of products, looks them up in Yotpo (one request per
	 * Yotpo_V3_Client::LOOKUP_MAX products) and sets their stored IDs and fingerprints to what Yotpo has. upsert()
	 * then skips a product whose data Yotpo already has, updates one whose data differs, and creates
	 * one Yotpo doesn't have. Without this, the full sync tried to create every product: three
	 * requests for each one Yotpo already had, even when nothing had changed.
	 *
	 * On an error, the products not yet compared lose their fingerprints, so they are all sent.
	 *
	 * @param WC_Product[] $products Published products.
	 * @return WP_Error|null
	 */
	public static function compare_products( $client, array $products ) {
		$payloads = array();
		foreach ( $products as $product ) {
			$payloads[ $product->get_id() ] = Yotpo_Product_Payload::product( $product );
		}
		return self::compare( $client, self::product_spec(), $payloads );
	}

	/**
	 * The same for the variations of a variable product, once the product is known in Yotpo.
	 *
	 * @return WP_Error|null
	 */
	public static function compare_variants( $client, $product ) {
		$yotpo_product_id = (int) get_post_meta( $product->get_id(), self::META_PRODUCT_ID, true );
		if ( ! $yotpo_product_id ) {
			return null;
		}
		$payloads = array();
		foreach ( self::variation_ids( $product ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$payloads[ $variation_id ] = Yotpo_Product_Payload::variant( $variation, $product );
			}
		}
		return self::compare( $client, self::variant_spec( $yotpo_product_id ), $payloads );
	}

	/**
	 * The variations compare_variants() covers: those of a variable product, none for any other.
	 *
	 * @return int[]
	 */
	private static function variation_ids( $product ) {
		return $product->is_type( 'variable' ) ? $product->get_children() : array();
	}

	/**
	 * Drops the fingerprints of these products, so the next sync sends them. Used instead of
	 * compare_products() when the comparison cannot run.
	 *
	 * @param int[] $product_ids
	 */
	public static function forget_product_fingerprints( array $product_ids ) {
		self::forget_hashes( $product_ids, self::META_PRODUCT_HASH );
	}

	/**
	 * The same for the variations compare_variants() covers, instead of compare_variants().
	 */
	public static function forget_variant_fingerprints( $product ) {
		self::forget_hashes( self::variation_ids( $product ), self::META_VARIANT_HASH );
	}

	/**
	 * The one way fingerprints are dropped, for the public forget_*_fingerprints() and for compare().
	 *
	 * @param string $hash_key META_PRODUCT_HASH or META_VARIANT_HASH.
	 */
	private static function forget_hashes( array $post_ids, $hash_key ) {
		foreach ( $post_ids as $post_id ) {
			delete_post_meta( $post_id, $hash_key );
		}
	}

	/**
	 * Sets the stored Yotpo ID and fingerprint of each payload's object to what a lookup of the
	 * whole set found. Writes the same post meta upsert() reads (see the META_*_HASH constants).
	 *
	 * @param Yotpo_V3_Client $client   The API client.
	 * @param array           $spec     product_spec() or variant_spec().
	 * @param array           $payloads What would be sent, keyed by WooCommerce ID (the external ID).
	 * @return WP_Error|null
	 */
	private static function compare( $client, $spec, array $payloads ) {
		$not_compared = array_keys( $payloads );
		foreach ( array_chunk( $payloads, Yotpo_V3_Client::LOOKUP_MAX, true ) as $chunk ) {
			$items = $client->lookup_all( $spec, array_keys( $chunk ) );
			if ( is_wp_error( $items ) ) {
				self::forget_hashes( $not_compared, $spec['hash'] );
				return $items;
			}
			$in_yotpo = Yotpo_V3_Client::index_by_external_id( $items );
			foreach ( $chunk as $post_id => $payload ) {
				if ( ! isset( $in_yotpo[ (string) $post_id ] ) ) {
					// Not in Yotpo (any more): forget a stored ID, so it is created.
					delete_post_meta( $post_id, $spec['meta'] );
					delete_post_meta( $post_id, $spec['hash'] );
					continue;
				}
				$item = $in_yotpo[ (string) $post_id ];
				update_post_meta( $post_id, $spec['meta'], (int) $item['yotpo_id'] );
				if ( Yotpo_Payload_Compare::same( $payload, $item ) ) {
					update_post_meta( $post_id, $spec['hash'], self::fingerprint( $spec, $payload ) );
				} else {
					delete_post_meta( $post_id, $spec['hash'] );
				}
			}
			$not_compared = array_diff( $not_compared, array_keys( $chunk ) );
		}
		return null;
	}

	/**
	 * Updates one product or variant by its Yotpo ID.
	 *
	 * @return WP_Error|null Null when Yotpo accepted it.
	 */
	private static function patch( $client, $spec, $yotpo_id, $payload ) {
		return Yotpo_V3_Client::response_error( $client->request( 'PATCH', $spec['path'] . '/' . $yotpo_id, array( $spec['key'] => $payload ) ) );
	}

	/**
	 * Blocklists a product that is gone or not published. Nothing to do if Yotpo never had it.
	 * The outcome is remembered in post meta, so saving a draft again costs no API call.
	 */
	public static function blocklist_product( $client, $product_id, $force = false ) {
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
			self::remember_blocklist( $product_id );
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
				self::remember_blocklist( $product_id );
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
		self::remember_blocklist( $product_id );
		ytdbg( 'Yotpo ID ' . $yotpo_id, 'Product sync: product #' . $product_id . ' blocklisted,' );
		return $yotpo_id;
	}

	private static function remember_blocklist( $product_id ) {
		if ( get_post_status( $product_id ) ) { // A deleted product has no meta to keep.
			update_post_meta( $product_id, self::META_BLOCKLISTED, '1' );
			// Yotpo no longer has the data last sent; publishing again must send it.
			delete_post_meta( $product_id, self::META_PRODUCT_HASH );
		}
	}

	/**
	 * A product synced as published again is no longer blocklisted.
	 */
	public static function forget_blocklist( $product_id ) {
		delete_post_meta( $product_id, self::META_BLOCKLISTED );
	}

	/**
	 * Marks a deleted variation as discontinued in Yotpo. Nothing to do if Yotpo never had it.
	 *
	 * @return WP_Error|null The first error of the lookups or the update.
	 */
	public static function discontinue_variant( $client, $variation_id, $parent_id ) {
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
		return Yotpo_V3_Client::response_error( $response );
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
		$error    = Yotpo_V3_Client::response_error( $response );
		if ( $error ) {
			return $error;
		}
		$found = Yotpo_V3_Client::index_by_external_id( isset( $response['body'][ $spec['list_key'] ] ) ? (array) $response['body'][ $spec['list_key'] ] : array() );
		if ( ! isset( $found[ (string) $post_id ] ) ) {
			return 0;
		}
		if ( get_post_status( $post_id ) ) {
			update_post_meta( $post_id, $spec['meta'], (int) $found[ (string) $post_id ]['yotpo_id'] );
		}
		return (int) $found[ (string) $post_id ]['yotpo_id'];
	}

	private static function find_yotpo_product_id( $client, $product_id ) {
		return self::find_yotpo_id( $client, self::product_spec(), $product_id );
	}

	private static function find_yotpo_variant_id( $client, $yotpo_product_id, $variation_id ) {
		return self::find_yotpo_id( $client, self::variant_spec( $yotpo_product_id ), $variation_id );
	}

	/**
	 * Network, server and rate-limit errors are worth retrying; a 4xx rejection is not.
	 */
	public static function is_retryable( $error ) {
		return in_array( $error->get_error_code(), array( 'yotpo_rate_limited', 'yotpo_network', 'yotpo_server' ), true );
	}
}
