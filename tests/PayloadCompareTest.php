<?php
/**
 * The matching rules of the full sync's comparison: when Yotpo's copy of a product counts as having
 * the data the plugin would send.
 */
class PayloadCompareTest extends WP_UnitTestCase {

	public function test_same_data_in_yotpos_formats_matches() {
		$sent   = array(
			'name'               => 'Wool Beanie',
			'price'              => 18.0,
			'inventory_quantity' => 5,
			'custom_properties'  => array( 'is_blocklisted' => false ),
			'gtins'              => array(
				array( 'declared_type' => 'UPC', 'value' => '725272730706' ),
				array( 'declared_type' => 'ISBN', 'value' => '9780306406157' ),
			),
		);
		$stored = array(
			'name'               => 'Wool Beanie ',
			'price'              => '18.00',
			'inventory_quantity' => '5',
			'custom_properties'  => array( 'is_blocklisted' => 'false', 'other' => 'ignored' ),
			'gtins'              => array(
				array( 'value' => '9780306406157', 'declared_type' => 'ISBN' ),
				array( 'value' => '725272730706', 'declared_type' => 'UPC' ),
			),
			'extra_field'        => 'Yotpo has it, the plugin does not send it',
		);

		$this->assertTrue( Yotpo_Payload_Compare::same( $sent, $stored ) );
	}

	public function test_a_missing_value_matches_only_an_empty_one() {
		$this->assertTrue( Yotpo_Payload_Compare::same( array( 'description' => '' ), array() ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'name' => 'Wool Beanie' ), array() ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'name' => 'Wool Beanie' ), null ) );
	}

	public function test_flags_are_read_as_flags() {
		$this->assertTrue( Yotpo_Payload_Compare::same( array( 'is_discontinued' => false ), array( 'is_discontinued' => 'false' ) ) );
		$this->assertTrue( Yotpo_Payload_Compare::same( array( 'is_discontinued' => true ), array( 'is_discontinued' => '1' ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'is_discontinued' => true ), array( 'is_discontinued' => 'false' ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'is_discontinued' => false ), array( 'is_discontinued' => 'maybe' ) ), 'Unreadable counts as different' );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'is_discontinued' => false ), array() ), 'Missing counts as different' );
	}

	public function test_counts_are_exact() {
		$this->assertTrue( Yotpo_Payload_Compare::same( array( 'inventory_quantity' => 5 ), array( 'inventory_quantity' => 5.0 ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'inventory_quantity' => 5 ), array( 'inventory_quantity' => 6 ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'inventory_quantity' => 5 ), array( 'inventory_quantity' => 'five' ) ) );
	}

	public function test_prices_match_on_the_value_the_plugin_sends() {
		$this->assertTrue( Yotpo_Payload_Compare::same( array( 'price' => 18.0 ), array( 'price' => '18.00' ) ), 'Only the format differs' );
		$this->assertTrue( Yotpo_Payload_Compare::same( array( 'price' => 18.456 ), array( 'price' => '18.4560' ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'price' => 18.0 ), array( 'price' => '18.01' ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'price' => 18.0 ), array( 'price' => '18.004' ) ), 'Any real difference in the sent price is a change' );

		// The store's display decimals do not matter: the plugin sends the raw price.
		foreach ( array( 0, 2, 3 ) as $decimals ) {
			update_option( 'woocommerce_price_num_decimals', $decimals );
			$this->assertFalse( Yotpo_Payload_Compare::same( array( 'price' => 18.456 ), array( 'price' => '18.45' ) ), "{$decimals} decimals" );
			$this->assertFalse( Yotpo_Payload_Compare::same( array( 'price' => 9.99 ), array( 'price' => '9.50' ) ), "{$decimals} decimals" );
		}
	}

	public function test_a_list_or_object_where_a_single_value_was_sent_is_different() {
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'name' => 'Wool Beanie' ), array( 'name' => array( 'Wool Beanie' ) ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'is_discontinued' => false ), array( 'is_discontinued' => array() ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( array( 'price' => 18.0 ), array( 'price' => array( 18 ) ) ) );
	}

	public function test_lists_differ_when_an_entry_differs() {
		$sent = array( 'options' => array( array( 'name' => 'Size', 'value' => 'M' ) ) );

		$this->assertFalse( Yotpo_Payload_Compare::same( $sent, array( 'options' => array( array( 'name' => 'Size', 'value' => 'L' ) ) ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( $sent, array( 'options' => array() ) ) );
		$this->assertFalse( Yotpo_Payload_Compare::same( $sent, array() ) );
	}
}
