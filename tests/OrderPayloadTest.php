<?php
/**
 * What an order looks like to Yotpo (Yotpo_Order_Payload): the create with its fulfillment, and the
 * fields sent when it changes afterwards.
 */
class OrderPayloadTest extends Yotpo_Test_Case {

	const FULFILLED_AT = 1792483200; // 2026-10-20 08:00:00 UTC.

	public function test_fulfilled_order() {
		$beanie   = $this->create_product();
		$hoodie   = $this->create_variable_product();
		$medium   = wc_get_product( $hoodie->get_children()[1] );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_order( array( array( $beanie, 2 ), array( $medium, 1 ) ) );
		$order->set_customer_id( $customer );
		$order->save();

		$payload = Yotpo_Order_Payload::order( $order, self::FULFILLED_AT );

		$this->assertSame( (string) $order->get_id(), $payload['external_id'] );
		$this->assertSame( (string) $order->get_order_number(), $payload['order_name'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $payload['order_date'] );
		$this->assertSame( 'USD', $payload['currency'] );
		$this->assertEquals( 86, $payload['total_price'] );
		$this->assertEquals( 86, $payload['subtotal_price'] );
		$this->assertSame( 'paid', $payload['payment_status'] );
		$this->assertSame( 'Credit card', $payload['payment_method'] );
		$this->assertSame( array( 'email' => 'dana@example.com', 'first_name' => 'Dana', 'last_name' => 'Levi', 'external_id' => (string) $customer ), $payload['customer'] );
		$this->assertSame( array( 'customer_country' => 'IL' ), $payload['custom_properties'] );
		$this->assertSame(
			array(
				array( 'external_product_id' => (string) $beanie->get_id(), 'quantity' => 2, 'total_price' => 36.0, 'subtotal_price' => 36.0 ),
				array( 'external_product_id' => (string) $hoodie->get_id(), 'quantity' => 1, 'total_price' => 50.0, 'subtotal_price' => 50.0, 'external_variant_id' => (string) $medium->get_id() ),
			),
			$payload['line_items']
		);
		$this->assertSame(
			array(
				array(
					'external_id'      => (string) $order->get_id(),
					'fulfillment_date' => '2026-10-20T08:00:00Z',
					'status'           => 'success',
					'fulfilled_items'  => array(
						array( 'external_product_id' => (string) $beanie->get_id(), 'quantity' => 2 ),
						array( 'external_product_id' => (string) $hoodie->get_id(), 'quantity' => 1 ),
					),
				),
			),
			$payload['fulfillments']
		);
		$this->assertArrayNotHasKey( 'cancellation', $payload );
	}

	public function test_guest_has_no_customer_id() {
		$payload = Yotpo_Order_Payload::order( $this->create_order( array( array( $this->create_product(), 1 ) ) ), self::FULFILLED_AT );

		$this->assertArrayNotHasKey( 'external_id', $payload['customer'] );
	}

	public function test_two_variations_of_one_product_are_one_fulfilled_item() {
		$hoodie = $this->create_variable_product();
		list( $small, $medium ) = $hoodie->get_children();
		$order  = $this->create_order( array( array( wc_get_product( $small ), 1 ), array( wc_get_product( $medium ), 2 ) ) );

		$payload = Yotpo_Order_Payload::order( $order, self::FULFILLED_AT );

		$this->assertSame( array( (string) $small, (string) $medium ), array_column( $payload['line_items'], 'external_variant_id' ), 'Each line keeps its variant' );
		$this->assertSame( array( array( 'external_product_id' => (string) $hoodie->get_id(), 'quantity' => 3 ) ), $payload['fulfillments'][0]['fulfilled_items'] );
	}

	public function test_fully_refunded_line_is_suppressed_but_still_fulfilled() {
		$beanie = $this->create_product();
		$belt   = $this->create_product( 'Leather Belt', 'YT-ACC-002', '25' );
		$order  = $this->create_order( array( array( $beanie, 1 ), array( $belt, 2 ) ) );
		$items  = array_keys( $order->get_items() );
		$order  = $this->refund( $order, array( $items[1] => 2 ) );

		$payload = Yotpo_Order_Payload::order( $order, self::FULFILLED_AT );

		$this->assertArrayNotHasKey( 'custom_properties', $payload['line_items'][0] );
		$this->assertSame( array( 'suppress_review_request' => true ), $payload['line_items'][1]['custom_properties'] );
		$this->assertSame( array( (string) $beanie->get_id(), (string) $belt->get_id() ), array_column( $payload['fulfillments'][0]['fulfilled_items'], 'external_product_id' ), 'A suppressed line left out would hold back the whole order' );
		$this->assertSame( 2, $payload['fulfillments'][0]['fulfilled_items'][1]['quantity'] );
		$this->assertSame( 'partially_refunded', $payload['payment_status'] );
	}

	public function test_partly_refunded_line_is_still_asked_about() {
		$order = $this->create_order( array( array( $this->create_product(), 3 ) ) );
		$order = $this->refund( $order, array( array_keys( $order->get_items() )[0] => 1 ) );

		$payload = Yotpo_Order_Payload::order( $order, self::FULFILLED_AT );

		$this->assertArrayNotHasKey( 'custom_properties', $payload['line_items'][0] );
		$this->assertSame( 3, $payload['line_items'][0]['quantity'] );
	}

	public function test_orders_yotpo_cannot_ask_about_are_skipped() {
		$product = $this->create_product();
		foreach ( array(
			'no email'      => array( 'email' => '' ),
			'no first name' => array( 'first_name' => ' ' ),
		) as $case => $billing ) {
			$result = Yotpo_Order_Payload::order( $this->create_order( array( array( $product, 1 ) ), 'completed', $billing ), self::FULFILLED_AT );
			$this->assertWPError( $result, $case );
			$this->assertSame( 'yotpo_order_skipped', $result->get_error_code(), $case );
		}

		$empty = $this->create_order( array() );
		$this->assertSame( 'yotpo_order_skipped', Yotpo_Order_Payload::order( $empty, self::FULFILLED_AT )->get_error_code(), 'No products' );
	}

	public function test_email_ending_in_a_digit_is_sent() {
		// 1.x skipped these; they are ordinary addresses.
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ), 'completed', array( 'email' => 'dana1@example.com' ) );

		$this->assertSame( 'dana1@example.com', Yotpo_Order_Payload::order( $order, self::FULFILLED_AT )['customer']['email'] );
	}

	public function test_update_of_a_paid_order_is_its_payment_status() {
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ) );

		$this->assertSame( array( 'payment_status' => 'paid', 'custom_properties' => array( 'customer_country' => 'IL' ) ), Yotpo_Order_Payload::update( $order ), 'Custom properties too: Yotpo replaces them on update' );
	}

	public function test_cancelled_order_sends_a_cancellation() {
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ) );
		$order->set_status( 'cancelled' );
		$order->save();

		$fields = Yotpo_Order_Payload::update( $order );

		$this->assertSame( 'voided', $fields['payment_status'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $fields['cancellation']['cancellation_date'] );
	}

	public function test_fully_refunded_order_sends_a_cancellation() {
		$order = $this->create_order( array( array( $this->create_product(), 2 ) ) );
		$order = $this->refund( $order, array( array_keys( $order->get_items() )[0] => 2 ) );

		$fields = Yotpo_Order_Payload::update( $order );

		$this->assertSame( 'refunded', $fields['payment_status'] );
		$this->assertArrayHasKey( 'cancellation', $fields );
		$this->assertSame( $fields, Yotpo_Order_Payload::update( wc_get_order( $order->get_id() ) ), 'Stable: sending it again changes nothing' );
	}

	public function test_partly_refunded_order_sends_no_cancellation() {
		$order = $this->create_order( array( array( $this->create_product(), 2 ) ) );
		$order = $this->refund( $order, array( array_keys( $order->get_items() )[0] => 1 ) );

		$this->assertSame( array( 'payment_status' => 'partially_refunded', 'custom_properties' => array( 'customer_country' => 'IL' ) ), Yotpo_Order_Payload::update( $order ) );
	}
}
