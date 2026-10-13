<?php
/**
 * Syncing one product to Yotpo's catalog (Core API V3): what is sent, and how API answers are handled.
 */
class CatalogSyncTest extends Yotpo_Test_Case {

	public function test_existing_product_is_found_and_updated_instead_of_duplicated() {
		$beanie = $this->create_product( 'Wool Beanie', 'YT-ACC-001', '18', array( 'brand' => 'Contoso', 'upc' => '725272730706' ) );
		$this->route_tokens();
		$this->route( 'POST', '#^/products$#', function () {
			return $this->respond( 409, array( 'errors' => array( array( 'message' => 'product already exists' ) ) ) );
		} );
		$this->route_lookup( 111, $beanie->get_id() );
		$this->route( 'PATCH', '#^/products/111$#', function () {
			return $this->respond( 200 );
		} );

		$result = Yotpo_Product_Sync::sync_product( $beanie->get_id() );

		$this->assertSame( 111, $result );
		$this->assertSame( array( 'POST /access_tokens', 'POST /products', 'GET /products', 'PATCH /products/111' ), $this->calls() );
		$this->assertStringContainsString( 'external_ids=' . $beanie->get_id(), $this->requests[2]['url'], 'Looked up by WooCommerce product ID' );
		$this->assertSame( 'tok-1', $this->requests[1]['token'] );
		$this->assertSame( 'tok-1', $this->requests[3]['token'] );
		$this->assertSame( 111, (int) get_post_meta( $beanie->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ), 'Yotpo ID cached' );

		$sent = $this->requests[1]['body']['product'];
		$this->assertSame( (string) $beanie->get_id(), $sent['external_id'] );
		$this->assertSame( 'Wool Beanie', $sent['name'] );
		$this->assertSame( 'YT-ACC-001', $sent['sku'] );
		$this->assertSame( 'Contoso', $sent['brand'] );
		$this->assertSame( array( array( 'declared_type' => 'UPC', 'value' => '725272730706' ) ), $sent['gtins'] );
		$this->assertEquals( 18, $sent['price'] );
		$this->assertSame( get_woocommerce_currency(), $sent['currency'] );
		$this->assertFalse( $sent['custom_properties']['is_blocklisted'] );
		$this->assertArrayNotHasKey( 'external_id', $this->requests[3]['body']['product'], 'An update does not resend the external ID' );
	}

	public function test_calls_are_paced_to_yotpos_rate_limit() {
		$beanie = $this->create_product();
		$this->route_tokens();
		$this->route( 'POST', '#^/products$#', function () {
			return $this->respond( 409 );
		} );
		$this->route_lookup( 111, $beanie->get_id() );
		$this->route( 'PATCH', '#^/products/111$#', function () {
			return $this->respond( 200 );
		} );

		Yotpo_Product_Sync::sync_product( $beanie->get_id() );

		for ( $i = 1; $i < count( $this->requests ); $i++ ) {
			$this->assertGreaterThanOrEqual( 0.19, $this->requests[ $i ]['time'] - $this->requests[ $i - 1 ]['time'], 'At most 5 calls per second' );
		}
	}

	public function test_variable_product_is_created_with_its_variations_as_variants() {
		$hoodie = $this->create_variable_product();
		$this->route_tokens();
		$this->route( 'POST', '#^/products$#', function () {
			return $this->respond( 201, array( 'product' => array( 'yotpo_id' => 222 ) ) );
		} );
		$next_variant = 5000;
		$this->route( 'POST', '#^/products/222/variants$#', function () use ( &$next_variant ) {
			$next_variant++;
			return $this->respond( 201, array( 'variant' => array( 'yotpo_id' => (string) $next_variant ) ) );
		} );

		$this->assertSame( 222, Yotpo_Product_Sync::sync_product( $hoodie->get_id() ) );

		$variants = array_values( array_filter( $this->requests, function ( $r ) {
			return 'POST' === $r['method'] && false !== strpos( $r['path'], '/variants' );
		} ) );
		$this->assertCount( 3, $variants );
		$this->assertSame( 1, $this->tokens_issued, 'One token for the whole product' );

		$variant = $variants[0]['body']['variant'];
		$this->assertContains( (int) $variant['external_id'], $hoodie->get_children(), 'External ID = variation ID' );
		$this->assertSame( 'Size', $variant['options'][0]['name'] );
		$this->assertContains( $variant['options'][0]['value'], array( 'S', 'M', 'L' ) );
		$this->assertStringStartsWith( 'YT-HOOD-VAR-', $variant['sku'] );
		$this->assertEquals( 50, $variant['price'] );
		$this->assertFalse( $variant['is_discontinued'] );
		foreach ( $hoodie->get_children() as $variation_id ) {
			$this->assertGreaterThan( 5000, (int) get_post_meta( $variation_id, Yotpo_Catalog_Api::META_VARIANT_ID, true ), 'Variant ID cached' );
		}
	}

	public function test_unchanged_product_is_not_sent_again_and_a_forced_sync_uses_the_cached_ids() {
		$hoodie = $this->create_variable_product();
		$this->route_tokens();
		$this->route( 'POST', '#^/products$#', function () {
			return $this->respond( 201, array( 'product' => array( 'yotpo_id' => 222 ) ) );
		} );
		$next_variant = 5000;
		$this->route( 'POST', '#^/products/222/variants$#', function () use ( &$next_variant ) {
			return $this->respond( 201, array( 'variant' => array( 'yotpo_id' => (string) ++$next_variant ) ) );
		} );
		Yotpo_Product_Sync::sync_product( $hoodie->get_id() );
		$this->requests = array();

		$this->assertSame( 222, Yotpo_Product_Sync::sync_product( $hoodie->get_id() ) );
		$this->assertSame( array(), $this->calls(), 'Nothing changed, so nothing is sent' );

		$this->route( 'PATCH', '#^/products/222(/variants/\d+)?$#', function () {
			return $this->respond( 200 );
		} );
		$this->assertSame( 222, Yotpo_Product_Sync::sync_product( $hoodie->get_id(), true ) );
		$this->assertCount( 4, $this->calls() );
		$this->assertSame( array( 'PATCH' ), array_values( array_unique( wp_list_pluck( $this->requests, 'method' ) ) ), 'Product and 3 variants updated, no lookups' );
	}

	public function test_product_deleted_in_yotpo_is_created_again() {
		$beanie = $this->product_known_as( 111 );
		$this->route( 'PATCH', '#^/products/111$#', function () {
			return $this->respond( 404, array( 'errors' => array( array( 'message' => 'product not found' ) ) ) );
		} );
		$this->route_lookup();
		$this->route( 'POST', '#^/products$#', function () {
			return $this->respond( 201, array( 'product' => array( 'yotpo_id' => 333 ) ) );
		} );

		$this->assertSame( 333, Yotpo_Product_Sync::sync_product( $beanie->get_id(), true ) );
		$this->assertSame( array( 'PATCH /products/111', 'GET /products', 'POST /products' ), $this->api_calls() );
		$this->assertSame( 333, (int) get_post_meta( $beanie->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
	}

	public function test_stale_cached_id_is_corrected_by_a_lookup() {
		$beanie = $this->product_known_as( 777 );
		$this->route( 'PATCH', '#^/products/777$#', function () {
			return $this->respond( 403, array( 'errors' => array( array( 'message' => 'forbidden' ) ) ) );
		} );
		$this->route_lookup( 333, $beanie->get_id() );
		$this->route( 'PATCH', '#^/products/333$#', function () {
			return $this->respond( 200 );
		} );

		$this->assertSame( 333, Yotpo_Product_Sync::sync_product( $beanie->get_id(), true ) );
		$this->assertSame( array( 'PATCH /products/777', 'GET /products', 'PATCH /products/333' ), $this->api_calls(), 'No duplicate created' );
		$this->assertSame( 333, (int) get_post_meta( $beanie->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
	}

	public function test_rejection_of_the_right_id_is_returned_not_retried() {
		$beanie = $this->product_known_as( 333 );
		$this->route( 'PATCH', '#^/products/333$#', function () {
			return $this->respond( 400, array( 'errors' => array( array( 'message' => 'price must be non-negative' ) ) ) );
		} );
		$this->route_lookup( 333, $beanie->get_id() );

		$result = Yotpo_Product_Sync::sync_product( $beanie->get_id(), true );

		$this->assertWPError( $result );
		$this->assertSame( 'yotpo_rejected', $result->get_error_code() );
		$this->assertSame( 'price must be non-negative', $result->get_error_message() );
		$this->assertFalse( Yotpo_Catalog_Api::is_retryable( $result ) );
		$this->assertSame( 333, (int) get_post_meta( $beanie->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ), 'ID kept' );
	}

	public function test_unpublished_product_is_blocklisted() {
		$belt = $this->create_product( 'Leather Belt', 'YT-ACC-002' );
		$belt->set_status( 'draft' );
		$belt->save();
		$this->route_tokens();
		$this->route_lookup( 444, $belt->get_id() );
		$this->route( 'PATCH', '#^/products/444$#', function () {
			return $this->respond( 200 );
		} );

		$this->assertSame( 444, Yotpo_Product_Sync::sync_product( $belt->get_id() ) );
		$last = end( $this->requests );
		$this->assertSame( 'PATCH', $last['method'] );
		$this->assertSame( array( 'product' => array( 'custom_properties' => array( 'is_blocklisted' => true ) ) ), $last['body'], 'Only the blocklist flag is sent' );
	}

	public function test_rate_limit_is_a_retryable_error_carrying_retry_after() {
		$beanie = $this->product_known_as( 333 );
		$this->route( 'PATCH', '#^/products/333$#', function () {
			return $this->respond( 429, array( 'errors' => array( array( 'message' => 'too many requests' ) ) ), array( 'retry-after' => '7' ) );
		} );

		$result = Yotpo_Product_Sync::sync_product( $beanie->get_id(), true );

		$this->assertWPError( $result );
		$this->assertSame( 'yotpo_rate_limited', $result->get_error_code() );
		$this->assertSame( 7, (int) $result->get_error_data() );
		$this->assertTrue( Yotpo_Catalog_Api::is_retryable( $result ) );
	}

	public function test_expired_token_is_refreshed_once_and_the_call_retried() {
		$beanie = $this->product_known_as( 333 );
		$patches = 0;
		$this->route( 'PATCH', '#^/products/333$#', function () use ( &$patches ) {
			return 1 === ++$patches ? $this->respond( 401 ) : $this->respond( 200 );
		} );

		$this->assertSame( 333, Yotpo_Product_Sync::sync_product( $beanie->get_id(), true ) );
		$this->assertSame( 2, $patches );
		$this->assertSame( 2, $this->tokens_issued );
		$this->assertSame( 'tok-2', end( $this->requests )['token'], 'Retried with the new token' );
	}

	public function test_payload_leaves_out_skus_yotpo_rejects_and_cuts_long_names() {
		$product = $this->create_product();
		$product->set_sku( 'BAD SKU/1' );
		$this->assertArrayNotHasKey( 'sku', Yotpo_Product_Sync::product_payload( $product ) );
		$product->set_name( str_repeat( 'x', 300 ) );
		$this->assertSame( 255, strlen( Yotpo_Product_Sync::product_payload( $product )['name'] ) );
	}

	public function test_variation_without_its_own_sku_sends_none() {
		$hoodie    = $this->create_variable_product();
		$variation = wc_get_product( $hoodie->get_children()[0] );
		$variation->set_sku( '' );
		$variation->save();
		$this->assertArrayNotHasKey( 'sku', Yotpo_Product_Sync::variant_payload( wc_get_product( $variation->get_id() ), $hoodie ), 'The inherited parent SKU is not the variant\'s' );
	}

	public function test_disabled_variation_is_sent_as_discontinued() {
		$hoodie    = $this->create_variable_product();
		$variation = wc_get_product( $hoodie->get_children()[0] );
		$variation->set_status( 'private' );
		$variation->save();
		$this->assertTrue( Yotpo_Product_Sync::variant_payload( wc_get_product( $variation->get_id() ), $hoodie )['is_discontinued'] );
	}

	/** A published product already synced as $yotpo_id, with a token issued on demand. */
	private function product_known_as( $yotpo_id ) {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, $yotpo_id );
		$this->route_tokens();
		return $product;
	}

	/** calls() without the token requests. */
	private function api_calls() {
		return array_values( array_diff( $this->calls(), array( 'POST /access_tokens' ) ) );
	}
}
