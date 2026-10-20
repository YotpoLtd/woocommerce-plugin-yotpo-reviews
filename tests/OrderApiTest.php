<?php
/**
 * Orders in Yotpo (Yotpo_Order_Api), against a fake Yotpo that enforces what the Core API documents:
 * an order's products must exist, fulfilled items must be in the order, a lookup by external IDs takes
 * at most 100 and nothing else, and a second create of the same order answers 409.
 */
class OrderApiTest extends Yotpo_Test_Case {

	/** @var array Yotpo's products by external ID; each also has 'variants' by external ID. */
	private $catalog = array();

	/** @var array Yotpo's orders by external ID. */
	private $orders = array();

	private $next_id = 5000;

	/** @var int|null When set, POST /orders answers this code. */
	private $create_fails = null;

	/** @var int|null When set, POST /products answers this code. */
	private $product_create_fails = null;

	/** @var bool When set, Yotpo gets the product from elsewhere (a CSV, say) just before POST /products. */
	private $product_appears_on_create = false;

	public function set_up() {
		parent::set_up();
		$this->catalog                   = array();
		$this->orders                    = array();
		$this->create_fails              = null;
		$this->product_create_fails      = null;
		$this->product_appears_on_create = false;
		$this->route_tokens();
		$this->route_fake_yotpo();
	}

	public function test_fulfilled_order_is_created_with_its_fulfillment_in_one_request() {
		$beanie = $this->in_yotpo( $this->create_product() );
		$order  = $this->create_order( array( array( $beanie, 1 ) ) );

		$yotpo_id = Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$this->assertSame( array( 'POST /access_tokens', 'GET /orders', 'POST /orders' ), $this->calls(), 'A lookup, then one create' );
		$this->assertSame( $this->orders[ $order->get_id() ]['yotpo_id'], $yotpo_id );
		$this->assertSame( 'success', $this->orders[ $order->get_id() ]['fulfillments'][0]['status'] );
		$this->assertSame( $yotpo_id, (int) wc_get_order( $order->get_id() )->get_meta( Yotpo_Order_Api::META_ORDER_ID ) );
	}

	public function test_order_is_never_sent_twice() {
		$order = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ) );
		Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );
		$this->requests = array();

		$yotpo_id = Yotpo_Order_Api::send_fulfilled( $this->client(), wc_get_order( $order->get_id() ), time() );

		$this->assertSame( array(), $this->calls(), 'The stored Yotpo ID answers without a request' );
		$this->assertSame( $this->orders[ $order->get_id() ]['yotpo_id'], $yotpo_id );
	}

	public function test_order_sent_by_1x_is_found_and_not_sent_again() {
		$order = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ) );
		$this->orders[ $order->get_id() ] = array( 'yotpo_id' => 777, 'external_id' => (string) $order->get_id(), 'fulfillments' => array() );

		$yotpo_id = Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$this->assertSame( 777, $yotpo_id );
		$this->assertSame( array( 'POST /access_tokens', 'GET /orders' ), $this->calls(), 'No second order, no second fulfillment' );
		$this->assertSame( array(), $this->orders[ $order->get_id() ]['fulfillments'] );
		$this->assertSame( 777, (int) wc_get_order( $order->get_id() )->get_meta( Yotpo_Order_Api::META_ORDER_ID ) );
	}

	public function test_products_yotpo_lacks_are_created_first() {
		$beanie = $this->create_product();
		$hoodie = $this->create_variable_product();
		$medium = wc_get_product( $hoodie->get_children()[1] );
		$order  = $this->create_order( array( array( $beanie, 1 ), array( $medium, 1 ) ) );

		Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$calls = $this->calls();
		$this->assertSame( array( 'POST /access_tokens', 'GET /orders', 'GET /products' ), array_slice( $calls, 0, 3 ), 'One lookup for both products' );
		$this->assertSame( 'POST /orders', end( $calls ), 'The order goes last' );
		$this->assertArrayHasKey( $beanie->get_id(), $this->catalog );
		$this->assertSame( array(), $this->catalog[ $hoodie->get_id() ]['variants'], 'Without its variations: one request each, left to the product sync' );
		$this->assertCount( 2, $this->orders[ $order->get_id() ]['line_items'] );
		$this->assertSame( (string) $medium->get_id(), $this->orders[ $order->get_id() ]['line_items'][1]['external_variant_id'], 'The purchased variation is still on the line' );
	}

	public function test_product_yotpo_has_is_not_changed() {
		$beanie = $this->create_product();
		$this->catalog[ $beanie->get_id() ] = array( 'yotpo_id' => 42, 'external_id' => (string) $beanie->get_id(), 'name' => 'Set by CSV', 'variants' => array() );
		$order  = $this->create_order( array( array( $beanie, 1 ) ) );

		Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$this->assertSame( array( 'POST /access_tokens', 'GET /orders', 'GET /products', 'POST /orders' ), $this->calls() );
		$this->assertSame( 'Set by CSV', $this->catalog[ $beanie->get_id() ]['name'] );
		$this->assertSame( 42, (int) get_post_meta( $beanie->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
	}

	public function test_product_yotpo_got_meanwhile_is_not_changed() {
		$beanie = $this->create_product();
		$order  = $this->create_order( array( array( $beanie, 1 ) ) );

		$this->product_appears_on_create = true; // Between the lookup and the create.

		$result = Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$this->assertSame( $this->orders[ $order->get_id() ]['yotpo_id'], $result );
		$this->assertSame( array(), preg_grep( '#^PATCH /products#', $this->calls() ), 'Found, not updated' );
		$this->assertSame( 'Set by CSV', $this->catalog[ $beanie->get_id() ]['name'] );
		$this->assertSame( $this->catalog[ $beanie->get_id() ]['yotpo_id'], (int) get_post_meta( $beanie->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
	}

	public function test_line_of_a_deleted_product_yotpo_never_had_is_dropped() {
		$beanie = $this->in_yotpo( $this->create_product() );
		$gone   = $this->create_product( 'Discontinued Scarf', 'YT-ACC-009', '30' );
		$order  = $this->create_order( array( array( $beanie, 1 ), array( $gone, 1 ) ) );
		wp_delete_post( $gone->get_id(), true );

		Yotpo_Order_Api::send_fulfilled( $this->client(), wc_get_order( $order->get_id() ), time() );

		$sent = $this->orders[ $order->get_id() ];
		$this->assertSame( array( (string) $beanie->get_id() ), array_column( $sent['line_items'], 'external_product_id' ) );
		$this->assertSame( array( (string) $beanie->get_id() ), array_column( $sent['fulfillments'][0]['fulfilled_items'], 'external_product_id' ) );
	}

	public function test_product_yotpo_rejects_fails_the_order_instead_of_dropping_its_line() {
		$beanie = $this->in_yotpo( $this->create_product() );
		$scarf  = $this->create_product( 'Silk Scarf', 'YT-ACC-010', '30' );
		$order  = $this->create_order( array( array( $beanie, 1 ), array( $scarf, 1 ) ) );

		$this->product_create_fails = 400;

		$result = Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$this->assertSame( 'yotpo_rejected', $result->get_error_code() );
		$this->assertSame( array(), $this->orders, 'An order sent without a line could never get it back' );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Yotpo_Order_Api::META_ORDER_ID ) );
	}

	public function test_order_left_without_products_is_skipped() {
		$gone  = $this->create_product();
		$order = $this->create_order( array( array( $gone, 1 ) ) );
		wp_delete_post( $gone->get_id(), true );

		$result = Yotpo_Order_Api::send_fulfilled( $this->client(), wc_get_order( $order->get_id() ), time() );

		$this->assertSame( 'yotpo_order_skipped', $result->get_error_code() );
		$this->assertSame( array(), $this->orders );
	}

	public function test_order_older_than_180_days_is_skipped() {
		$order = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ) );
		$order->set_date_created( time() - 181 * DAY_IN_SECONDS );
		$order->save();

		$result = Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$this->assertSame( 'yotpo_order_skipped', $result->get_error_code() );
		$this->assertSame( array( 'POST /access_tokens', 'GET /orders' ), $this->calls(), 'Yotpo would reject it' );
	}

	public function test_create_answering_409_finds_the_order() {
		$order              = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ) );
		$this->create_fails = 409; // Another job created it between the lookup and the create.

		$yotpo_id = Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$this->assertSame( $this->orders[ $order->get_id() ]['yotpo_id'], $yotpo_id );
		$this->assertSame( array( 'POST /access_tokens', 'GET /orders', 'POST /orders', 'GET /orders' ), $this->calls() );
	}

	public function test_failed_create_is_retryable_and_remembers_nothing() {
		$order              = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ) );
		$this->create_fails = 500;

		$result = Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );

		$this->assertTrue( Yotpo_Catalog_Api::is_retryable( $result ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Yotpo_Order_Api::META_ORDER_ID ) );
	}

	public function test_cancellation_is_sent_once() {
		$order = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ) );
		Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'cancelled' );
		$order->save();
		$this->requests = array();

		Yotpo_Order_Api::send_update( $this->client(), $order );
		Yotpo_Order_Api::send_update( $this->client(), wc_get_order( $order->get_id() ) );

		$yotpo_id = $this->orders[ $order->get_id() ]['yotpo_id'];
		$this->assertSame( array( 'PATCH /orders/' . $yotpo_id ), $this->calls(), 'The second update matches the first: no request' );
		$this->assertArrayHasKey( 'cancellation_date', $this->orders[ $order->get_id() ]['cancellation'] );
		$this->assertSame( 'voided', $this->orders[ $order->get_id() ]['payment_status'] );
	}

	public function test_order_cancelled_before_it_was_sent_still_gets_its_cancellation() {
		$order = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ), 'cancelled' );
		Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );
		$yotpo_id = $this->orders[ $order->get_id() ]['yotpo_id'];
		$this->assertArrayNotHasKey( 'cancellation', $this->orders[ $order->get_id() ], 'The create carries no cancellation' );
		$this->requests = array();

		Yotpo_Order_Api::send_update( $this->client(), wc_get_order( $order->get_id() ) );

		$this->assertSame( array( 'PATCH /orders/' . $yotpo_id ), $this->calls(), 'The cancellation was never sent: the update goes out' );
		$this->assertArrayHasKey( 'cancellation_date', $this->orders[ $order->get_id() ]['cancellation'] );
	}

	public function test_update_right_after_create_sends_nothing() {
		$order = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ) );
		Yotpo_Order_Api::send_fulfilled( $this->client(), $order, time() );
		$this->requests = array();

		Yotpo_Order_Api::send_update( $this->client(), wc_get_order( $order->get_id() ) );

		$this->assertSame( array(), $this->calls(), 'The create already carried the payment status' );
	}

	public function test_update_of_an_order_yotpo_lacks_sends_nothing() {
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ), 'processing' );

		$this->assertSame( 0, Yotpo_Order_Api::send_update( $this->client(), $order ) );
		$this->assertSame( array( 'POST /access_tokens', 'GET /orders' ), $this->calls() );
	}

	public function test_update_with_a_stale_id_looks_the_order_up_again() {
		$order = $this->create_order( array( array( $this->in_yotpo( $this->create_product() ), 1 ) ), 'cancelled' );
		$this->orders[ $order->get_id() ] = array( 'yotpo_id' => 321, 'external_id' => (string) $order->get_id() );
		$order->update_meta_data( Yotpo_Order_Api::META_ORDER_ID, 999 );
		$order->save_meta_data();

		$this->assertSame( 321, Yotpo_Order_Api::send_update( $this->client(), $order ) );
		$this->assertSame( array( 'POST /access_tokens', 'PATCH /orders/999', 'GET /orders', 'PATCH /orders/321' ), $this->calls() );
	}

	public function test_lookups_hold_at_most_100_orders() {
		$product = $this->in_yotpo( $this->create_product() );
		$orders  = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$orders[] = $this->create_order( array( array( $product, 1 ) ) );
		}
		$this->orders[ $orders[120]->get_id() ] = array( 'yotpo_id' => 55, 'external_id' => (string) $orders[120]->get_id() );

		$found = Yotpo_Order_Api::find_yotpo_ids( $this->client(), $orders );

		$this->assertSame( array( $orders[120]->get_id() => 55 ), $found );
		$this->assertSame( array( 'POST /access_tokens', 'GET /orders', 'GET /orders' ), $this->calls() );
	}

	// -----------------------------------------------------------------------------------------

	private function client() {
		return Yotpo_V3_Client::from_settings();
	}

	/** Puts the product in the fake catalog, with its Yotpo ID stored as the product sync would. */
	private function in_yotpo( $product ) {
		$yotpo_id                              = ++$this->next_id;
		$this->catalog[ $product->get_id() ] = array( 'yotpo_id' => $yotpo_id, 'external_id' => (string) $product->get_id(), 'variants' => array() );
		update_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, $yotpo_id );
		return $product;
	}

	/** The external IDs of a lookup; null when the request breaks Yotpo's rules for them. */
	private function lookup_ids( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( array_diff( array_keys( $query ), array( 'external_ids' ) ) ) {
			return null; // "Can't come with page_info or limit."
		}
		$ids = '' === (string) ( $query['external_ids'] ?? '' ) ? array() : explode( ',', $query['external_ids'] );
		return count( $ids ) > 100 ? null : $ids;
	}

	private function route_fake_yotpo() {
		$this->route( 'GET', '#^/orders$#', function ( $request ) {
			$ids = $this->lookup_ids( $request['url'] );
			if ( null === $ids ) {
				return $this->respond( 400 );
			}
			$found = array();
			foreach ( $ids as $id ) {
				if ( isset( $this->orders[ $id ] ) ) {
					$found[] = array( 'yotpo_id' => $this->orders[ $id ]['yotpo_id'], 'external_id' => (string) $id );
				}
			}
			return $this->respond( 200, array( 'orders' => $found ) );
		} );
		$this->route( 'POST', '#^/orders$#', function ( $request ) {
			$order = $request['body']['order'];
			if ( 409 === $this->create_fails ) {
				$this->orders[ $order['external_id'] ] = array( 'yotpo_id' => ++$this->next_id, 'external_id' => $order['external_id'] );
			} elseif ( $this->create_fails ) {
				return $this->respond( $this->create_fails );
			}
			if ( isset( $this->orders[ $order['external_id'] ] ) ) {
				return $this->respond( 409, array( 'errors' => array( array( 'message' => 'order already exists' ) ) ) );
			}
			$in_order = array();
			foreach ( $order['line_items'] as $line ) {
				if ( ! isset( $this->catalog[ $line['external_product_id'] ] ) ) {
					return $this->respond( 400, array( 'errors' => array( array( 'message' => 'unknown product ' . $line['external_product_id'] ) ) ) );
				}
				$in_order[] = $line['external_product_id'];
			}
			foreach ( $order['fulfillments'] as $fulfillment ) {
				foreach ( $fulfillment['fulfilled_items'] as $item ) {
					if ( ! in_array( $item['external_product_id'], $in_order, true ) ) {
						return $this->respond( 400, array( 'errors' => array( array( 'message' => 'fulfilled product not in the order' ) ) ) );
					}
				}
			}
			$this->orders[ $order['external_id'] ] = array_merge( $order, array( 'yotpo_id' => ++$this->next_id ) );
			return $this->respond( 201, array( 'order' => array( 'yotpo_id' => $this->next_id ) ) );
		} );
		$this->route( 'PATCH', '#^/orders/\d+$#', function ( $request ) {
			$yotpo_id = (int) substr( $request['path'], strlen( '/orders/' ) );
			foreach ( $this->orders as $id => $order ) {
				if ( $order['yotpo_id'] === $yotpo_id ) {
					$this->orders[ $id ] = array_merge( $order, $request['body']['order'] );
					return $this->respond( 200 );
				}
			}
			return $this->respond( 404 );
		} );
		$this->route( 'GET', '#^/products$#', function ( $request ) {
			$ids = $this->lookup_ids( $request['url'] );
			if ( null === $ids ) {
				return $this->respond( 400 );
			}
			$found = array();
			foreach ( $ids as $id ) {
				if ( isset( $this->catalog[ $id ] ) ) {
					$found[] = array_diff_key( $this->catalog[ $id ], array( 'variants' => 1 ) );
				}
			}
			return $this->respond( 200, array( 'products' => $found ) );
		} );
		$this->route( 'POST', '#^/products$#', function ( $request ) {
			$product = $request['body']['product'];
			if ( $this->product_appears_on_create ) {
				$this->catalog[ $product['external_id'] ] = array( 'yotpo_id' => ++$this->next_id, 'external_id' => $product['external_id'], 'name' => 'Set by CSV', 'variants' => array() );
			}
			if ( $this->product_create_fails ) {
				return $this->respond( $this->product_create_fails, array( 'errors' => array( array( 'message' => 'invalid product' ) ) ) );
			}
			if ( isset( $this->catalog[ $product['external_id'] ] ) ) {
				return $this->respond( 409 );
			}
			$this->catalog[ $product['external_id'] ] = array_merge( $product, array( 'yotpo_id' => ++$this->next_id, 'variants' => array() ) );
			return $this->respond( 201, array( 'product' => array( 'yotpo_id' => $this->next_id ) ) );
		} );
		$this->route( 'POST', '#^/products/\d+/variants$#', function ( $request ) {
			$yotpo_id = (int) explode( '/', $request['path'] )[2];
			foreach ( $this->catalog as $id => $product ) {
				if ( $product['yotpo_id'] === $yotpo_id ) {
					$variant = $request['body']['variant'];
					$this->catalog[ $id ]['variants'][ $variant['external_id'] ] = array_merge( $variant, array( 'yotpo_id' => ++$this->next_id ) );
					return $this->respond( 201, array( 'variant' => array( 'yotpo_id' => $this->next_id ) ) );
				}
			}
			return $this->respond( 404 );
		} );
	}
}
