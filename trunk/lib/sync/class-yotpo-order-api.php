<?php

defined( 'ABSPATH' ) || exit;

/**
 * Orders in Yotpo (Core API V3): lookups by order ID, create with the fulfillment, and updates.
 * Remembers each order's Yotpo ID, and a fingerprint of the update last accepted, in order meta
 * (through WC_Order, so it works with either order storage).
 *
 * - Every create is preceded by a lookup: Yotpo shows orders 1.x sent through the legacy API as V3
 *   orders too, so an order 1.x sent is found and never sent again.
 * - Fields for both directions come from Yotpo_Order_Payload.
 */
class Yotpo_Order_Api {

	// Also written out in uninstall.php, which runs without this class loaded: keep both in sync.
	const META_ORDER_ID    = '_yotpo_v3_order_id';
	const META_UPDATE_HASH = '_yotpo_v3_order_hash'; // Fingerprint of the update fields Yotpo last accepted.
	const MAX_AGE_DAYS     = 180; // Yotpo rejects order dates more than 6 months back; 180 days is always inside that.
	const ABSENT_PREFIX    = 'wc_yotpo_order_absent_'; // Transient per order; also written out in uninstall.php.
	const ABSENT_FOR       = 600; // Seconds an update remembers that Yotpo doesn't have an order, so repeated cancel/refund events cost one lookup.

	/**
	 * The lookup spec for Yotpo_V3_Client::lookup_all().
	 */
	private static function spec() {
		return array( 'path' => '/orders', 'list_key' => 'orders' );
	}

	/**
	 * Sends an order that reached the fulfilled status: creates it in Yotpo with its fulfillment, after
	 * making sure Yotpo has its products. An order Yotpo already has (sent before, by this version or by
	 * 1.x) is left as it is: it never gets a second fulfillment.
	 *
	 * @param Yotpo_V3_Client $client
	 * @param WC_Order        $order
	 * @param int             $fulfilled_at Unix time the order reached the fulfilled status.
	 * @param bool            $created      Set to whether this call created the order (false when Yotpo already had it).
	 * @return int|WP_Error The Yotpo order ID. WP_Error 'yotpo_order_skipped' for an order that can't be sent.
	 */
	public static function send_fulfilled( $client, $order, $fulfilled_at, &$created = false ) {
		$created  = false;
		$yotpo_id = self::find_yotpo_id( $client, $order );
		if ( is_wp_error( $yotpo_id ) || $yotpo_id ) {
			return $yotpo_id;
		}
		$placed = $order->get_date_created();
		if ( $placed && $placed->getTimestamp() < time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS ) {
			return Yotpo_Order_Payload::skipped( 'it was placed more than ' . self::MAX_AGE_DAYS . ' days ago' );
		}
		$payload = Yotpo_Order_Payload::order( $order, $fulfilled_at );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload = self::with_known_products( $client, $payload );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$response = $client->request( 'POST', '/orders', array( 'order' => $payload ) );
		if ( 201 === $response['code'] || 200 === $response['code'] ) {
			$yotpo_id = isset( $response['body']['order']['yotpo_id'] ) ? (int) $response['body']['order']['yotpo_id'] : 0;
			if ( ! $yotpo_id ) {
				return new WP_Error( 'yotpo_bad_response', 'Create order returned no yotpo_id' );
			}
			self::remember( $order, $yotpo_id, Yotpo_Order_Payload::fingerprint( Yotpo_Order_Payload::update_sent_with_create( $order ) ) );
			$created = true;
			return $yotpo_id;
		}
		if ( 409 !== $response['code'] ) {
			return Yotpo_V3_Client::response_error( $response );
		}
		// Created meanwhile (a second job for the same order, say): find it.
		$yotpo_id = self::find_yotpo_id( $client, $order, true );
		if ( ! is_wp_error( $yotpo_id ) && ! $yotpo_id ) {
			return new WP_Error( 'yotpo_not_found', 'Create order answered "already exists" but the lookup found nothing' );
		}
		return $yotpo_id;
	}

	/**
	 * Sends what changed on an order Yotpo already has: its payment status, and the cancellation once it
	 * is cancelled or fully refunded. Nothing is sent when Yotpo doesn't have the order (it isn't
	 * fulfilled yet) or when the fields match the last update Yotpo accepted.
	 *
	 * An order with no stored Yotpo ID may still be in Yotpo (1.x sent it), so it is looked up. When Yotpo
	 * doesn't have it, that is remembered for ABSENT_FOR seconds: an order that was never sent (cancelled
	 * unpaid, refunded before fulfilment) would otherwise cost a lookup for each of its events.
	 *
	 * @return int|WP_Error The Yotpo order ID, or 0 when Yotpo doesn't have the order.
	 */
	public static function send_update( $client, $order ) {
		if ( ! $order->get_meta( self::META_ORDER_ID ) && get_transient( self::absent_key( $order ) ) ) {
			return 0;
		}
		$yotpo_id = self::find_yotpo_id( $client, $order );
		if ( is_wp_error( $yotpo_id ) ) {
			return $yotpo_id;
		}
		if ( ! $yotpo_id ) {
			set_transient( self::absent_key( $order ), 1, self::ABSENT_FOR );
			return 0;
		}
		$fields = Yotpo_Order_Payload::update( $order );
		$hash   = Yotpo_Order_Payload::fingerprint( $fields );
		if ( $order->get_meta( self::META_UPDATE_HASH ) === $hash ) {
			return $yotpo_id;
		}
		$response = $client->request( 'PATCH', '/orders/' . $yotpo_id, array( 'order' => $fields ) );
		if ( 404 === $response['code'] ) {
			// The stored ID is stale: look the order up again once.
			$yotpo_id = self::find_yotpo_id( $client, $order, true );
			if ( is_wp_error( $yotpo_id ) || ! $yotpo_id ) {
				return $yotpo_id;
			}
			$response = $client->request( 'PATCH', '/orders/' . $yotpo_id, array( 'order' => $fields ) );
		}
		$error = Yotpo_V3_Client::response_error( $response );
		if ( $error ) {
			return $error;
		}
		self::remember( $order, $yotpo_id, $hash );
		return $yotpo_id;
	}

	/**
	 * Looks these orders up in Yotpo, Yotpo_V3_Client::LOOKUP_MAX per request, and stores the Yotpo IDs found.
	 *
	 * @param WC_Order[] $orders
	 * @return int[]|WP_Error Yotpo order IDs by WooCommerce order ID, for the orders Yotpo has.
	 */
	public static function find_yotpo_ids( $client, array $orders ) {
		$found = array();
		foreach ( array_chunk( $orders, Yotpo_V3_Client::LOOKUP_MAX ) as $chunk ) {
			$ids = array();
			foreach ( $chunk as $order ) {
				$ids[] = $order->get_id();
			}
			$items = $client->lookup_all( self::spec(), $ids );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			$in_yotpo = Yotpo_V3_Client::index_by_external_id( $items );
			foreach ( $chunk as $order ) {
				if ( isset( $in_yotpo[ (string) $order->get_id() ] ) ) {
					$yotpo_id                   = (int) $in_yotpo[ (string) $order->get_id() ]['yotpo_id'];
					$found[ $order->get_id() ] = $yotpo_id;
					self::remember( $order, $yotpo_id );
				}
			}
		}
		return $found;
	}

	/**
	 * The order's Yotpo ID: the stored one, else from a lookup (0 when Yotpo doesn't have it).
	 *
	 * @param bool $lookup Ask Yotpo even when an ID is stored, and replace it.
	 * @return int|WP_Error
	 */
	private static function find_yotpo_id( $client, $order, $lookup = false ) {
		$stored = (int) $order->get_meta( self::META_ORDER_ID );
		if ( $stored && ! $lookup ) {
			return $stored;
		}
		if ( $stored ) {
			$order->delete_meta_data( self::META_ORDER_ID );
			$order->save_meta_data();
		}
		$found = self::find_yotpo_ids( $client, array( $order ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		return isset( $found[ $order->get_id() ] ) ? $found[ $order->get_id() ] : 0;
	}

	/**
	 * Drops the lines whose products Yotpo doesn't have and can't get (deleted from WooCommerce), after
	 * creating the products it can. The fulfillment keeps only products still in the order.
	 *
	 * @return array|WP_Error
	 */
	private static function with_known_products( $client, array $payload ) {
		$known = self::ensure_products( $client, Yotpo_Order_Payload::product_ids( $payload ) );
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		return Yotpo_Order_Payload::only_products( $payload, $known );
	}

	/**
	 * Makes sure Yotpo has these products before an order that contains them is sent: V3 rejects an
	 * order line whose product it doesn't know. A product with a stored Yotpo ID counts as known (the
	 * product sync keeps those right); the rest are looked up, and those Yotpo lacks are created, from
	 * the same data the product sync sends, without their variations. Runs whether or not "Sync products
	 * to Yotpo" is on, but only creates: a product Yotpo has is never changed here.
	 *
	 * A product deleted from WooCommerce can't be created, so it is left out of the answer and the
	 * caller drops its lines. A product that still exists but can't be created (Yotpo rejected it) is
	 * an error: an order is final once sent, so its line can't be dropped and added back later.
	 *
	 * @param int[] $product_ids
	 * @return int[]|WP_Error The products Yotpo has now, or the first error.
	 */
	private static function ensure_products( $client, array $product_ids ) {
		$known = array();
		$ask   = array();
		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $product_id ) {
			if ( get_post_meta( $product_id, Yotpo_Catalog_Api::META_PRODUCT_ID, true ) ) {
				$known[] = $product_id;
			} elseif ( $product_id ) {
				$ask[] = $product_id;
			}
		}
		$found = Yotpo_Catalog_Api::find_products( $client, $ask );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$known = array_merge( $known, $found );
		foreach ( array_diff( $ask, $found ) as $product_id ) {
			$created = self::create_missing_product( $client, $product_id );
			if ( is_wp_error( $created ) ) {
				ytdbg( $created->get_error_message(), 'Order sync: product #' . $product_id . ' could not be created in Yotpo:' );
				return $created;
			}
			if ( $created ) {
				$known[] = $product_id;
			}
		}
		return $known;
	}

	/**
	 * Creates a product Yotpo lacks, for ensure_products(). Only the product: its variations are one
	 * request each, and an order line is accepted without them (V3 keeps the external_variant_id of a
	 * variation it doesn't know), so they are left to the product sync. One that isn't published is
	 * blocklisted right after, as the product sync would.
	 *
	 * @return bool|WP_Error False when WooCommerce no longer has the product.
	 */
	private static function create_missing_product( $client, $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'trash' === $product->get_status() ) {
			return false;
		}
		$yotpo_id = Yotpo_Catalog_Api::create_product( $client, $product );
		if ( is_wp_error( $yotpo_id ) ) {
			return $yotpo_id;
		}
		if ( 'publish' !== $product->get_status() ) {
			$result = Yotpo_Catalog_Api::blocklist_product( $client, $product_id, true );
			if ( is_wp_error( $result ) && Yotpo_V3_Client::is_retryable( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	private static function absent_key( $order ) {
		return self::ABSENT_PREFIX . $order->get_id();
	}

	private static function remember( $order, $yotpo_id, $hash = null ) {
		delete_transient( self::absent_key( $order ) );
		$order->update_meta_data( self::META_ORDER_ID, (int) $yotpo_id );
		if ( null !== $hash ) {
			$order->update_meta_data( self::META_UPDATE_HASH, $hash );
		}
		$order->save_meta_data();
	}
}
