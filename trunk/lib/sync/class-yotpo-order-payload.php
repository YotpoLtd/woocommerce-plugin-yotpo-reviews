<?php

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce → Yotpo field mapping for orders (Core API V3).
 *
 * An order is sent once, when it reaches the status that means fulfilled, with its fulfillment in the
 * same request: V3 can't refund, remove or un-suppress a single line after the order is created, so the
 * lines must be final when it is sent.
 *
 * - External ID = the WooCommerce order ID, the same ID 1.x sent to the legacy API as order_id.
 * - A fully refunded line is sent with suppress_review_request, so it gets no review request. A line
 *   refunded in part (1 of 3) is still asked about.
 * - The fulfillment lists every line's product at its full quantity, suppressed lines included: a
 *   suppressed line left out of it holds back review requests for the whole order.
 */
class Yotpo_Order_Payload {

	const MAX_LINE_ITEMS = 500; // Yotpo's maximum per order.

	/**
	 * The order with its fulfillment, for create.
	 *
	 * @param WC_Order $order
	 * @param int      $fulfilled_at Unix time the order reached the fulfilled status.
	 * @return array|WP_Error WP_Error 'yotpo_order_skipped' when the order can't get a review request.
	 */
	public static function order( $order, $fulfilled_at ) {
		$customer = self::customer( $order );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$line_items = self::line_items( $order );
		if ( ! $line_items ) {
			return self::skipped( 'it has no products' );
		}
		$payload = array(
			'external_id'    => (string) $order->get_id(),
			'order_name'     => self::limit( $order->get_order_number(), 255 ),
			'order_date'     => self::date( $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ),
			'currency'       => $order->get_currency(),
			'total_price'    => self::money( $order->get_total() ),
			'subtotal_price' => self::money( (float) $order->get_subtotal() - (float) $order->get_discount_total() ),
			'payment_status' => self::payment_status( $order ),
			'customer'       => $customer,
			'line_items'     => $line_items,
			'fulfillments'   => array( self::fulfillment( $order, $line_items, $fulfilled_at ) ),
		);
		$payment_method = trim( (string) $order->get_payment_method_title() );
		if ( '' !== $payment_method ) {
			$payload['payment_method'] = self::limit( $payment_method, 64 );
		}
		$custom = self::custom_properties( $order );
		if ( $custom ) {
			$payload['custom_properties'] = $custom;
		}
		/**
		 * Filters the order fields sent to Yotpo. The external ID is the order ID and must not change.
		 */
		return (array) apply_filters( 'wc_yotpo_order_payload', $payload, $order );
	}

	/**
	 * The fields of an order already in Yotpo that can change after it was sent: the payment status, and
	 * the cancellation when the order is cancelled or fully refunded. A cancellation cancels the order's
	 * scheduled review requests. A refund of part of the order can't stop the request for one line: V3
	 * takes no line-level refunds.
	 *
	 * The order's custom properties travel with every update: Yotpo replaces them with what an update
	 * carries, and an update without them cleared customer_country in a live test.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function update( $order ) {
		$fields = array( 'payment_status' => self::payment_status( $order ) );
		$custom = self::custom_properties( $order );
		if ( $custom ) {
			$fields['custom_properties'] = $custom;
		}
		if ( self::is_cancelled( $order ) ) {
			$fields['cancellation'] = array( 'cancellation_date' => self::date( self::cancelled_at( $order ) ) );
		}
		/**
		 * Filters the order fields sent to Yotpo when an order already there changes.
		 */
		return (array) apply_filters( 'wc_yotpo_order_update_payload', $fields, $order );
	}

	/**
	 * Cancelled, or refunded in full (by status or by the refunds' total).
	 */
	public static function is_cancelled( $order ) {
		return $order->has_status( array( 'cancelled', 'refunded' ) ) || self::fully_refunded( $order );
	}

	/**
	 * The order's custom properties: the billing country, which Yotpo needs for Google seller ratings
	 * outside the US.
	 */
	private static function custom_properties( $order ) {
		$country = (string) $order->get_billing_country();
		return '' === $country ? array() : array( 'customer_country' => $country );
	}

	/**
	 * The customer, or WP_Error when Yotpo can't ask them for a review: Yotpo Reviews needs the
	 * customer's name, and an email address to write to.
	 */
	private static function customer( $order ) {
		$email = trim( (string) $order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			return self::skipped( 'it has no valid billing email' );
		}
		$first_name = trim( (string) $order->get_billing_first_name() );
		if ( '' === $first_name ) {
			return self::skipped( 'it has no billing first name' );
		}
		$customer = array(
			'email'      => self::limit( $email, 255 ),
			'first_name' => self::limit( $first_name, 255 ),
			'last_name'  => self::limit( trim( (string) $order->get_billing_last_name() ), 255 ),
		);
		if ( $order->get_customer_id() ) {
			$customer['external_id'] = (string) $order->get_customer_id();
		}
		return $customer;
	}

	/**
	 * One line per product line of the order. Lines without a product or with no quantity left after
	 * edits are not sent; fully refunded lines are sent suppressed.
	 */
	private static function line_items( $order ) {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product_id = (int) $item->get_product_id();
			$quantity   = (int) $item->get_quantity();
			if ( ! $product_id || $quantity <= 0 ) {
				continue;
			}
			$line = array(
				'external_product_id' => (string) $product_id,
				'quantity'            => $quantity,
				'total_price'         => self::money( (float) $item->get_total() + (float) $item->get_total_tax() ),
				'subtotal_price'      => self::money( $item->get_total() ),
			);
			if ( $item->get_variation_id() ) {
				$line['external_variant_id'] = (string) $item->get_variation_id();
			}
			if ( abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) >= $quantity ) {
				$line['custom_properties'] = array( 'suppress_review_request' => true );
			}
			$lines[] = $line;
		}
		return array_slice( $lines, 0, self::MAX_LINE_ITEMS );
	}

	/**
	 * The one fulfillment of the order. Yotpo identifies fulfilled items by product, so two variations of
	 * one product are one item with their quantities added. Every line counts, suppressed ones too.
	 */
	private static function fulfillment( $order, array $line_items, $fulfilled_at ) {
		$quantities = array();
		foreach ( $line_items as $line ) {
			$id                = $line['external_product_id'];
			$quantities[ $id ] = ( isset( $quantities[ $id ] ) ? $quantities[ $id ] : 0 ) + $line['quantity'];
		}
		$items = array();
		foreach ( $quantities as $id => $quantity ) {
			$items[] = array( 'external_product_id' => (string) $id, 'quantity' => $quantity );
		}
		return array(
			'external_id'      => (string) $order->get_id(),
			'fulfillment_date' => self::date( $fulfilled_at ),
			'status'           => 'success',
			'fulfilled_items'  => $items,
		);
	}

	private static function payment_status( $order ) {
		if ( self::fully_refunded( $order ) || $order->has_status( 'refunded' ) ) {
			return 'refunded';
		}
		if ( (float) $order->get_total_refunded() > 0 ) {
			return 'partially_refunded';
		}
		if ( $order->has_status( array( 'cancelled', 'failed' ) ) ) {
			return 'voided';
		}
		return $order->is_paid() || $order->get_date_paid() ? 'paid' : 'pending';
	}

	private static function fully_refunded( $order ) {
		$total = (float) $order->get_total();
		return $total > 0 && (float) $order->get_total_refunded() >= $total;
	}

	/**
	 * When the order was cancelled: the last refund's date for a refunded order, else the order's
	 * last change (the status change). Stable, so sending it again changes nothing.
	 */
	private static function cancelled_at( $order ) {
		$refunds = $order->get_refunds();
		if ( $refunds && ! $order->has_status( 'cancelled' ) && $refunds[0]->get_date_created() ) {
			return $refunds[0]->get_date_created()->getTimestamp();
		}
		return $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : time();
	}

	private static function skipped( $reason ) {
		return new WP_Error( 'yotpo_order_skipped', 'Not sent to Yotpo: ' . $reason );
	}

	private static function date( $timestamp ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp );
	}

	private static function money( $amount ) {
		return round( (float) $amount, wc_get_price_decimals() );
	}

	private static function limit( $text, $max ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
