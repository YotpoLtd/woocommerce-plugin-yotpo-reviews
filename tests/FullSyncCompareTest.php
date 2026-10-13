<?php
/**
 * The full sync compares products with Yotpo before sending them: one lookup per page of products
 * (and one per variable product for its variations), then only what Yotpo lacks or has differently
 * is sent.
 */
class FullSyncCompareTest extends Yotpo_Test_Case {

	/** @var array Yotpo's products by external ID; each also has 'variants' by external ID. */
	private $catalog = array();

	private $next_id = 1000;

	public function set_up() {
		parent::set_up();
		$this->catalog = array();
		$this->route_tokens();
		$this->route_fake_catalog();
	}

	public function test_catalog_yotpo_already_has_costs_only_lookups() {
		$beanie = $this->create_product( 'Wool Beanie', 'YT-ACC-001', '18', array( 'brand' => 'Contoso', 'upc' => '725272730706', 'isbn' => '9780306406157' ) );
		$belt   = $this->create_product( 'Leather Belt', 'YT-ACC-002', '25' );
		$hoodie = $this->create_variable_product();
		foreach ( array( $beanie, $belt, $hoodie ) as $product ) {
			$this->put_in_yotpo( $product );
		}
		// Yotpo returns values in its own format: price as a string, GTINs in another order, no empty description.
		$this->catalog[ $beanie->get_id() ]['price'] = '18.00';
		$this->catalog[ $beanie->get_id() ]['gtins'] = array_reverse( $this->catalog[ $beanie->get_id() ]['gtins'] );
		unset( $this->catalog[ $belt->get_id() ]['description'] );

		$this->full_sync();

		$this->assertSame( array( 'POST /access_tokens', 'GET /products', 'GET /products/' . $this->yotpo_id( $hoodie ) . '/variants' ), $this->calls(), 'One lookup for the page, one for the variations; nothing sent' );
		$state = Yotpo_Product_Sync::state();
		$this->assertSame( Yotpo_Sync_State::STATUS_COMPLETE, $state['status'] );
		$this->assertSame( 3, (int) $state['synced'] );
	}

	public function test_only_what_differs_is_sent() {
		$beanie = $this->create_product();
		$belt   = $this->create_product( 'Leather Belt', 'YT-ACC-002', '25' );
		$hoodie = $this->create_variable_product();
		foreach ( array( $beanie, $belt, $hoodie ) as $product ) {
			$this->put_in_yotpo( $product );
		}
		$this->catalog[ $belt->get_id() ]['name'] = 'Old name';
		$medium                                   = $hoodie->get_children()[1];
		$this->catalog[ $hoodie->get_id() ]['variants'][ $medium ]['price'] = 45;

		$this->full_sync();

		$this->assertSame( array( 'PATCH /products/' . $this->yotpo_id( $belt ), 'PATCH /products/' . $this->yotpo_id( $hoodie ) . '/variants/' . $this->catalog[ $hoodie->get_id() ]['variants'][ $medium ]['yotpo_id'] ), $this->sent() );
		$this->assertSame( 'Leather Belt', $this->catalog[ $belt->get_id() ]['name'] );
		$this->assertEquals( 50, $this->catalog[ $hoodie->get_id() ]['variants'][ $medium ]['price'] );
	}

	public function test_product_yotpo_lacks_is_created_with_one_request() {
		$beanie = $this->create_product();
		$belt   = $this->create_product( 'Leather Belt', 'YT-ACC-002', '25' );
		$this->put_in_yotpo( $beanie );

		$this->full_sync();

		$this->assertSame( array( 'POST /products' ), $this->sent(), 'No "already exists" round trip' );
		$this->assertArrayHasKey( $belt->get_id(), $this->catalog );
		$this->assertSame( $this->yotpo_id( $belt ), (int) get_post_meta( $belt->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
	}

	public function test_stale_stored_id_is_corrected_without_a_duplicate() {
		$beanie = $this->create_product();
		$this->put_in_yotpo( $beanie );
		update_post_meta( $beanie->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, 999999 );

		$this->full_sync();

		$this->assertSame( array(), $this->sent() );
		$this->assertSame( $this->yotpo_id( $beanie ), (int) get_post_meta( $beanie->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
	}

	public function test_a_second_full_sync_sends_nothing() {
		$this->create_product();
		$this->create_variable_product();

		$this->full_sync();
		$this->assertNotEmpty( $this->sent(), 'The first one creates the catalog' );
		$this->requests = array();
		$this->full_sync();

		$this->assertSame( array(), $this->sent() );
	}

	public function test_lookups_hold_at_most_100_products() {
		for ( $i = 1; $i <= 150; $i++ ) {
			$this->put_in_yotpo( $this->create_product( "Product $i", "SKU-$i", '10' ) );
		}

		$this->full_sync();

		$lookups = array_values( array_filter( $this->requests, function ( $request ) {
			return 'GET /products' === $request['method'] . ' ' . $request['path'];
		} ) );
		$this->assertCount( 2, $lookups, 'Two pages of 100' );
		foreach ( $lookups as $lookup ) {
			$this->assertLessThanOrEqual( 100, count( $this->external_ids( $lookup['url'] ) ) );
		}
		$this->assertSame( array(), $this->sent() );
		$this->assertSame( 150, (int) Yotpo_Product_Sync::state()['synced'] );
	}

	public function test_failed_lookup_sends_everything_as_before() {
		$beanie = $this->create_product();
		$this->put_in_yotpo( $beanie );
		$this->full_sync(); // Stored ID and fingerprint now match Yotpo.
		$this->requests  = array();
		$this->lookup_ok = false;

		$this->full_sync();

		$this->assertSame( array( 'PATCH /products/' . $this->yotpo_id( $beanie ) ), $this->sent(), 'Sent although unchanged: the comparison could not run' );
		$this->assertSame( Yotpo_Sync_State::STATUS_COMPLETE, Yotpo_Product_Sync::state()['status'] );
	}

	public function test_paginated_lookups_are_followed_to_the_last_page() {
		$products = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$products[] = $this->create_product( "Product $i", "SKU-$i", '10' );
			$this->put_in_yotpo( $products[ $i - 1 ] );
		}
		$this->page_size = 10; // Yotpo answers 10 at a time, whatever limit was asked.

		$this->full_sync();

		$lookups = array_values( array_filter( $this->requests, function ( $request ) {
			return 'GET /products' === $request['method'] . ' ' . $request['path'];
		} ) );
		$this->assertCount( 3, $lookups, 'Three pages of the one lookup' );
		$this->assertSame( array(), $this->sent(), 'Nothing is created or updated: every product was found' );
		foreach ( $products as $product ) {
			$this->assertSame( $this->yotpo_id( $product ), (int) get_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
		}
		$this->assertSame( 25, (int) Yotpo_Product_Sync::state()['synced'] );
	}

	// -----------------------------------------------------------------------------------------

	/** @var bool False makes product lookups fail with a 500. */
	private $lookup_ok = true;

	/** @var int|null Items per page of a lookup answer; null answers everything on one page. */
	private $page_size = null;

	private function full_sync() {
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP ); // Jobs the product saves queued.
		delete_option( Yotpo_Sync_State::STATE_OPTION );
		$this->assertTrue( Yotpo_Product_Sync::start_full_sync() );
		$this->run_jobs();
	}

	/** Puts the product (and its variations) in the fake Yotpo catalog with the data the plugin would send. */
	private function put_in_yotpo( $product ) {
		$product = wc_get_product( $product->get_id() );
		$entry   = array_merge( Yotpo_Product_Payload::product( $product ), array( 'external_id' => (string) $product->get_id(), 'yotpo_id' => ++$this->next_id, 'variants' => array() ) );
		foreach ( $product->get_children() as $variation_id ) {
			$entry['variants'][ $variation_id ] = array_merge(
				Yotpo_Product_Payload::variant( wc_get_product( $variation_id ), $product ),
				array( 'external_id' => (string) $variation_id, 'yotpo_id' => ++$this->next_id )
			);
		}
		$this->catalog[ $product->get_id() ] = $entry;
	}

	private function yotpo_id( $product ) {
		return $this->catalog[ $product->get_id() ]['yotpo_id'];
	}

	/** Requests that create or update something in Yotpo. */
	private function sent() {
		return array_values( array_filter( $this->calls(), function ( $call ) {
			return 0 === strpos( $call, 'PATCH ' ) || ( 0 === strpos( $call, 'POST ' ) && 'POST /access_tokens' !== $call );
		} ) );
	}

	private function external_ids( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return isset( $query['external_ids'] ) ? explode( ',', $query['external_ids'] ) : array();
	}

	/**
	 * One page of a lookup answer. With $page_size set, a page holds at most that many items and
	 * pagination.next_page_info (the offset of the next page) says where to continue.
	 */
	private function page_of( $list_key, array $found, $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$offset = isset( $query['page_info'] ) ? (int) $query['page_info'] : 0;
		$size   = $this->page_size ? $this->page_size : max( 1, count( $found ) );
		$next = $offset + $size < count( $found ) ? (string) ( $offset + $size ) : null;
		return array( $list_key => array_slice( $found, $offset, $size ), 'pagination' => array( 'next_page_info' => $next ) );
	}

	/** A minimal Yotpo catalog: lookups by external IDs, creates and updates of products and variants. */
	private function route_fake_catalog() {
		$this->route( 'GET', '#^/products$#', function ( $request ) {
			if ( ! $this->lookup_ok ) {
				return $this->respond( 500 );
			}
			$found = array();
			foreach ( $this->external_ids( $request['url'] ) as $id ) {
				if ( isset( $this->catalog[ $id ] ) ) {
					$found[] = array_diff_key( $this->catalog[ $id ], array( 'variants' => 1 ) );
				}
			}
			return $this->respond( 200, $this->page_of( 'products', $found, $request['url'] ) );
		} );
		$this->route( 'POST', '#^/products$#', function ( $request ) {
			$product = $request['body']['product'];
			if ( isset( $this->catalog[ $product['external_id'] ] ) ) {
				return $this->respond( 409 );
			}
			$this->catalog[ $product['external_id'] ] = array_merge( $product, array( 'yotpo_id' => ++$this->next_id, 'variants' => array() ) );
			return $this->respond( 201, array( 'product' => array( 'yotpo_id' => $this->next_id ) ) );
		} );
		$this->route( 'PATCH', '#^/products/\d+$#', function ( $request ) {
			$id = $this->external_id_of( (int) substr( $request['path'], strlen( '/products/' ) ) );
			if ( null === $id ) {
				return $this->respond( 404 );
			}
			$this->catalog[ $id ] = array_merge( $this->catalog[ $id ], $request['body']['product'] );
			return $this->respond( 200 );
		} );
		$this->route( 'GET', '#^/products/\d+/variants$#', function ( $request ) {
			$product = $this->external_id_of( (int) explode( '/', $request['path'] )[2] );
			$found   = array();
			foreach ( $this->external_ids( $request['url'] ) as $id ) {
				if ( null !== $product && isset( $this->catalog[ $product ]['variants'][ $id ] ) ) {
					$found[] = $this->catalog[ $product ]['variants'][ $id ];
				}
			}
			return $this->respond( 200, $this->page_of( 'variants', $found, $request['url'] ) );
		} );
		$this->route( 'POST', '#^/products/\d+/variants$#', function ( $request ) {
			$product = $this->external_id_of( (int) explode( '/', $request['path'] )[2] );
			$variant = $request['body']['variant'];
			if ( isset( $this->catalog[ $product ]['variants'][ $variant['external_id'] ] ) ) {
				return $this->respond( 409 );
			}
			$this->catalog[ $product ]['variants'][ $variant['external_id'] ] = array_merge( $variant, array( 'yotpo_id' => ++$this->next_id ) );
			return $this->respond( 201, array( 'variant' => array( 'yotpo_id' => (string) $this->next_id ) ) );
		} );
		$this->route( 'PATCH', '#^/products/\d+/variants/\d+$#', function ( $request ) {
			$parts   = explode( '/', $request['path'] );
			$product = $this->external_id_of( (int) $parts[2] );
			foreach ( null === $product ? array() : $this->catalog[ $product ]['variants'] as $id => $variant ) {
				if ( (int) $variant['yotpo_id'] === (int) $parts[4] ) {
					$this->catalog[ $product ]['variants'][ $id ] = array_merge( $variant, $request['body']['variant'] );
					return $this->respond( 200 );
				}
			}
			return $this->respond( 404 );
		} );
	}

	private function external_id_of( $yotpo_id ) {
		foreach ( $this->catalog as $external_id => $product ) {
			if ( (int) $product['yotpo_id'] === $yotpo_id ) {
				return $external_id;
			}
		}
		return null;
	}
}
