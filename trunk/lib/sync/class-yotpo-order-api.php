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

	/**
	 * The lookup spec for Yotpo_Catalog_Api::lookup_all().
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
	 * @return int|WP_Error The Yotpo order ID. WP_Error 'yotpo_order_skipped' for an order that can't be sent.
	 */
	public static function send_fulfilled( $client, $order, $fulfilled_at ) {
		$yotpo_id = self::find_yotpo_id( $client, $order );
		if ( is_wp_error( $yotpo_id ) || $yotpo_id ) {
			return $yotpo_id;
		}
		$created = $order->get_date_created();
		if ( $created && $created->getTimestamp() < time() - self::MAX_AGE_DAYS * DAY_IN_SECONDS ) {
			return new WP_Error( 'yotpo_order_skipped', 'Not sent to Yotpo: it was placed more than ' . self::MAX_AGE_DAYS . ' days ago' );
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
			// The fingerprint covers what the create carried. It carries no cancellation, so an order
			// cancelled or refunded by now still gets its cancellation from send_update().
			$sent = Yotpo_Order_Payload::update( $order );
			unset( $sent['cancellation'] );
			self::remember( $order, $yotpo_id, self::fingerprint( $sent ) );
			return $yotpo_id;
		}
		if ( 409 !== $response['code'] ) {
			return Yotpo_Catalog_Api::response_error( $response );
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
	 * @return int|WP_Error The Yotpo order ID, or 0 when Yotpo doesn't have the order.
	 */
	public static function send_update( $client, $order ) {
		$yotpo_id = self::find_yotpo_id( $client, $order );
		if ( is_wp_error( $yotpo_id ) || ! $yotpo_id ) {
			return $yotpo_id;
		}
		$fields = Yotpo_Order_Payload::update( $order );
		$hash   = self::fingerprint( $fields );
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
		$error = Yotpo_Catalog_Api::response_error( $response );
		if ( $error ) {
			return $error;
		}
		self::remember( $order, $yotpo_id, $hash );
		return $yotpo_id;
	}

	/**
	 * Looks these orders up in Yotpo, LOOKUP_MAX per request, and stores the Yotpo IDs found.
	 *
	 * @param WC_Order[] $orders
	 * @return int[]|WP_Error Yotpo order IDs by WooCommerce order ID, for the orders Yotpo has.
	 */
	public static function find_yotpo_ids( $client, array $orders ) {
		$found = array();
		foreach ( array_chunk( $orders, Yotpo_Catalog_Api::LOOKUP_MAX ) as $chunk ) {
			$ids = array();
			foreach ( $chunk as $order ) {
				$ids[] = $order->get_id();
			}
			$items = Yotpo_Catalog_Api::lookup_all( $client, self::spec(), $ids );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			$in_yotpo = Yotpo_Catalog_Api::index_by_external_id( $items );
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
		$product_ids = array();
		foreach ( $payload['line_items'] as $line ) {
			$product_ids[] = (int) $line['external_product_id'];
		}
		$known = Yotpo_Catalog_Api::ensure_products( $client, $product_ids );
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		$known = array_map( 'strval', $known );
		$keep  = function ( $item ) use ( $known ) {
			return in_array( (string) $item['external_product_id'], $known, true );
		};
		$payload['line_items'] = array_values( array_filter( $payload['line_items'], $keep ) );
		if ( ! $payload['line_items'] ) {
			return new WP_Error( 'yotpo_order_skipped', 'Not sent to Yotpo: none of its products are in WooCommerce or Yotpo any more' );
		}
		foreach ( $payload['fulfillments'] as $index => $fulfillment ) {
			$payload['fulfillments'][ $index ]['fulfilled_items'] = array_values( array_filter( $fulfillment['fulfilled_items'], $keep ) );
		}
		return $payload;
	}

	/**
	 * The fingerprint of update fields. The cancellation date is left out: it is derived from the order,
	 * and only whether the order is cancelled matters for sending again.
	 */
	private static function fingerprint( array $fields ) {
		if ( isset( $fields['cancellation'] ) ) {
			$fields['cancellation'] = true;
		}
		return md5( wp_json_encode( $fields ) );
	}

	private static function remember( $order, $yotpo_id, $hash = null ) {
		$order->update_meta_data( self::META_ORDER_ID, (int) $yotpo_id );
		if ( null !== $hash ) {
			$order->update_meta_data( self::META_UPDATE_HASH, $hash );
		}
		$order->save_meta_data();
	}
}
