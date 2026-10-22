<?php
/**
 * Y-tags (YBlocklist, YGroup_<group>, YCRF_<form tag>), the setting that turns them on, and the
 * brand and barcode sources aligned with Yotpo's Shopify integration.
 */
class ProductTagsTest extends Yotpo_Test_Case {

	public function test_tags_are_ignored_while_the_setting_is_off() {
		$product = $this->tagged_product( array( 'YBlocklist', 'YGroup_shirts', 'YCRF_size' ) );

		$this->assertFalse( Yotpo_Product_Sync::tags_on(), 'Off by default' );
		$payload = Yotpo_Product_Sync::product_payload( $product );
		$this->assertArrayNotHasKey( 'group_name', $payload );
		$this->assertArrayNotHasKey( 'custom_properties', $payload, 'Yotpo keeps the blocklist and form set there' );
	}

	public function test_y_tags_set_blocklist_group_and_form_without_their_prefix() {
		$this->set_setting( 'product_sync_tags', true );
		$product = $this->tagged_product( array( 'YBlocklist', 'YGroup_shirts', 'YCRF_Size', 'summer' ) );

		$payload = Yotpo_Product_Sync::product_payload( $product );

		$this->assertSame( 'shirts', $payload['group_name'] );
		$this->assertSame( array( 'is_blocklisted' => true, 'review_form_tag' => 'Size' ), $payload['custom_properties'], 'Form tag case is kept' );
	}

	public function test_without_y_tags_the_fields_are_cleared() {
		$this->set_setting( 'product_sync_tags', true );
		$product = $this->tagged_product( array( 'summer' ) );

		$payload = Yotpo_Product_Sync::product_payload( $product );

		$this->assertSame( '', $payload['group_name'], 'Out of its group' );
		$this->assertSame( array( 'is_blocklisted' => false, 'review_form_tag' => '' ), $payload['custom_properties'], 'Off the blocklist, default form' );
	}

	public function test_tag_names_are_case_sensitive() {
		$this->set_setting( 'product_sync_tags', true );
		$product = $this->tagged_product( array( 'yblocklist', 'YBLOCKLIST', 'ygroup_shirts', 'Ygroup_shirts', 'YCRFsize', 'ycrf_size' ) );

		$payload = Yotpo_Product_Sync::product_payload( $product );

		$this->assertSame( '', $payload['group_name'] );
		$this->assertSame( array( 'is_blocklisted' => false, 'review_form_tag' => '' ), $payload['custom_properties'] );
	}

	public function test_the_first_group_and_form_tag_in_alphabetical_order_take_hold() {
		$this->set_setting( 'product_sync_tags', true );
		$product = $this->tagged_product( array( 'YGroup_shirts', 'YGroup_pants', 'YCRF_tops', 'YCRF_bottoms' ) );

		$payload = Yotpo_Product_Sync::product_payload( $product );

		$this->assertSame( 'pants', $payload['group_name'] );
		$this->assertSame( 'bottoms', $payload['custom_properties']['review_form_tag'] );
	}

	public function test_group_names_are_made_valid_for_yotpo() {
		$this->assertSame( 'summer_shirts', Yotpo_Product_Tags::group_name( 'Summer Shirts' ) );
		$this->assertSame( 'kids-tops', Yotpo_Product_Tags::group_name( "kids'tops" ) );
		$this->assertSame( 'a-b', Yotpo_Product_Tags::group_name( 'a&b' ) );
		$this->assertSame( 'caf-', Yotpo_Product_Tags::group_name( 'Café' ), 'One dash per character, not per byte' );
		$this->assertSame( 'z-rich', Yotpo_Product_Tags::group_name( 'Zürich' ) );
		$this->assertSame( 'a_b', Yotpo_Product_Tags::group_name( "a\xC2\xA0b" ), 'A non-breaking space is a space' );
		$this->assertSame( 100, strlen( Yotpo_Product_Tags::group_name( str_repeat( 'x', 150 ) ) ) );
		$this->assertSame( '', Yotpo_Product_Tags::group_name( '!!' ), 'Nothing usable left' );

		$this->set_setting( 'product_sync_tags', true );
		$product = $this->tagged_product( array( 'YGroup_', 'YGroup_Summer & Sun' ) );
		$this->assertSame( 'summer_-_sun', Yotpo_Product_Sync::product_payload( $product )['group_name'], 'An empty YGroup_ is skipped; HTML entities are decoded' );
	}

	public function test_form_tags_lose_commas_and_keep_case() {
		$this->assertSame( 'SizeFit', Yotpo_Product_Tags::form_tag_name( 'Size,Fit' ) );
		$this->assertSame( 255, strlen( Yotpo_Product_Tags::form_tag_name( str_repeat( 'x', 300 ) ) ) );
	}

	public function test_turning_the_tags_on_starts_a_full_sync() {
		$this->set_setting( 'product_sync_tags', true );
		Yotpo_Product_Sync::tags_setting_changed( false, true, true, true );

		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ) );
		$this->assertSame( Yotpo_Sync_State::STATUS_RUNNING, Yotpo_Product_Sync::state()['status'] );
	}

	public function test_turning_the_tags_off_leaves_yotpo_as_it_is() {
		Yotpo_Product_Sync::tags_setting_changed( true, false, true, true );
		$this->assertSame( array(), $this->pending_jobs() );
	}

	public function test_turning_the_tags_on_with_the_sync_does_not_start_a_second_full_sync() {
		$this->set_setting( 'product_sync_tags', true );
		Yotpo_Product_Sync::tags_setting_changed( false, true, false, true );
		$this->assertSame( array(), $this->pending_jobs(), 'The sync turned on starts the full sync, setting_changed()' );

		Yotpo_Product_Sync::tags_setting_changed( false, true, true, false );
		$this->assertSame( array(), $this->pending_jobs(), 'The sync is off' );
	}

	public function test_turning_the_tags_on_during_a_full_sync_runs_another_once_it_is_done() {
		$this->assertTrue( Yotpo_Product_Sync::start_full_sync() );
		$this->set_setting( 'product_sync_tags', true );

		$this->assertTrue( Yotpo_Sync_Jobs::request_full_sync(), 'Accepted, not refused as busy' );
		$this->assertTrue( Yotpo_Product_Sync::state()['rerun'] );

		// The running sync completes.
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );
		$state           = Yotpo_Product_Sync::state();
		$state['status'] = Yotpo_Sync_State::STATUS_COMPLETE;
		update_option( Yotpo_Sync_State::STATE_OPTION, $state );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ), 'Started on the next page load' );
		$this->assertArrayNotHasKey( 'rerun', Yotpo_Product_Sync::state(), 'And only once' );
	}

	public function test_saving_the_settings_starts_a_full_sync_when_the_tags_are_turned_on() {
		require_once dirname( __DIR__ ) . '/trunk/templates/wc-yotpo-settings.php';
		$this->set_setting( 'widget_version', 'v2' ); // No widget lookup on save: this test is about the tags.
		$_POST = array( 'yotpo_app_key' => self::APP_KEY, 'yotpo_oauth_token' => 'test-secret', 'product_sync_enabled' => '1', 'product_sync_tags' => '1', 'yotpo_order_status' => 'wc-completed' );
		ob_start();
		wc_proccess_yotpo_settings();
		ob_end_clean();
		$_POST = array();

		$this->assertTrue( Yotpo_Product_Sync::tags_on() );
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_BATCH ) );
	}

	public function test_retagging_without_a_product_save_queues_the_product() {
		$product = $this->create_product();
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );

		wp_set_object_terms( $product->get_id(), array( 'YBlocklist' ), 'product_tag' );
		$this->assertSame( array(), $this->pending_jobs(), 'Tags off: nothing to do' );

		$this->set_setting( 'product_sync_tags', true );
		wp_set_object_terms( $product->get_id(), array( 'YGroup_shirts' ), 'product_tag' );
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_PRODUCT ) );
	}

	public function test_renaming_or_deleting_a_y_tag_queues_its_products() {
		$this->set_setting( 'product_sync_tags', true );
		$shirt = $this->tagged_product( array( 'YGroup_shirts' ), 'Shirt', 'SKU-SHIRT' );
		$tee   = $this->tagged_product( array( 'YGroup_shirts', 'summer' ), 'Tee', 'SKU-TEE' );
		$term  = get_term_by( 'name', 'YGroup_shirts', 'product_tag' );
		$plain = get_term_by( 'name', 'summer', 'product_tag' );
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );

		wp_update_term( $plain->term_id, 'product_tag', array( 'name' => 'summer-sale' ) );
		$this->assertSame( array(), $this->pending_jobs(), 'Not a Y-tag' );

		wp_update_term( $term->term_id, 'product_tag', array( 'name' => 'YGroup_tops' ) );
		$this->assertSame( array(), $this->queued_products(), 'Queued in the background, not in the request' );
		$this->run_tagged_products_jobs();
		$this->assertEqualsCanonicalizing( array( $shirt->get_id(), $tee->get_id() ), $this->queued_products() );
		$this->assertSame( 'tops', Yotpo_Product_Sync::product_payload( wc_get_product( $shirt->get_id() ) )['group_name'] );

		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );
		wp_delete_term( $term->term_id, 'product_tag' );
		$this->run_tagged_products_jobs();
		$this->assertEqualsCanonicalizing( array( $shirt->get_id(), $tee->get_id() ), $this->queued_products() );
		$this->assertSame( '', Yotpo_Product_Sync::product_payload( wc_get_product( $shirt->get_id() ) )['group_name'] );
	}

	public function test_a_plain_tag_renamed_into_a_y_tag_queues_its_products() {
		$this->set_setting( 'product_sync_tags', true );
		$product = $this->tagged_product( array( 'blocked' ) );
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );

		wp_update_term( get_term_by( 'name', 'blocked', 'product_tag' )->term_id, 'product_tag', array( 'name' => 'YBlocklist' ) );
		$this->run_tagged_products_jobs();

		$this->assertSame( array( $product->get_id() ), $this->queued_products() );
	}

	public function test_brand_comes_from_woocommerce_brands_first() {
		$this->assertTrue( taxonomy_exists( 'product_brand' ), 'WooCommerce Brands is part of this WooCommerce version' );
		$product = $this->create_product( 'Wool Beanie', 'YT-ACC-001', '18', array( 'brand' => 'Attribute Brand' ) );
		$this->assertSame( 'Attribute Brand', Yotpo_Product_Sync::product_payload( $product )['brand'], 'Attribute without a brand term' );

		wp_set_object_terms( $product->get_id(), array( 'Contoso', 'Acme' ), 'product_brand' );
		$this->assertSame( 'Acme', Yotpo_Product_Sync::product_payload( wc_get_product( $product->get_id() ) )['brand'], 'First brand alphabetically' );
	}

	public function test_barcodes_come_from_the_woocommerce_gtin_field_with_their_type() {
		$cases = array(
			'012345678905'   => 'UPC',
			'9780306406157'  => 'ISBN',
			'4006381333931'  => 'EAN',
			'96385074'       => 'EAN',
			'0-306-40615-2'  => 'ISBN',
		);
		foreach ( $cases as $value => $type ) {
			$value   = (string) $value; // PHP turned the numeric keys into integers.
			$product = $this->create_product( 'Item', '', '10' );
			$product->set_global_unique_id( $value );
			$product->save();
			$this->assertSame( array( array( 'declared_type' => $type, 'value' => $value ) ), Yotpo_Product_Sync::product_payload( $product )['gtins'], $value );
		}

		$product = $this->create_product( 'Case', '', '10' );
		$product->set_global_unique_id( '10012345678902' );
		$product->save();
		$this->assertSame( array( array( 'value' => '10012345678902' ) ), Yotpo_Product_Sync::product_payload( $product )['gtins'], 'A GTIN-14 goes without a type' );
	}

	public function test_a_barcode_in_both_the_field_and_an_attribute_is_sent_once() {
		$product = $this->create_product( 'Wool Beanie', 'YT-ACC-001', '18', array( 'upc' => '012345678905', 'isbn' => '9780306406157' ) );
		$product->set_global_unique_id( '012345678905' );
		$product->save();

		$this->assertSame(
			array( array( 'declared_type' => 'UPC', 'value' => '012345678905' ), array( 'declared_type' => 'ISBN', 'value' => '9780306406157' ) ),
			Yotpo_Product_Sync::product_payload( $product )['gtins']
		);
	}

	public function test_settings_page_shows_the_tags_switch() {
		require_once dirname( __DIR__ ) . '/trunk/templates/wc-yotpo-settings.php';
		require_once dirname( __DIR__ ) . '/trunk/lib/yotpo-api/Yotpo.php';
		$this->route( 'POST', '#^https://api\.yotpo\.com/oauth/token$#', function () {
			return $this->respond( 500 );
		} );
		ob_start();
		wc_display_yotpo_settings();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '#id="product_sync_tags" name="product_sync_tags" value="1"\s*/>#', $html, 'Unchecked by default' );
		$this->assertStringContainsString( '<code>YBlocklist</code>', $html );
	}

	// -----------------------------------------------------------------------------------------

	private function tagged_product( array $tags, $name = 'Wool Beanie', $sku = 'YT-ACC-001' ) {
		$product = $this->create_product( $name, $sku );
		wp_set_object_terms( $product->get_id(), $tags, 'product_tag' );
		return wc_get_product( $product->get_id() );
	}

	/** Runs the jobs that queue a renamed or deleted Y-tag's products, and nothing else. */
	private function run_tagged_products_jobs() {
		foreach ( array_keys( $this->pending_jobs( Yotpo_Sync_Jobs::HOOK_TAGGED ) ) as $action_id ) {
			ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'phpunit' );
		}
	}

	/** The product IDs of the queued product jobs. */
	private function queued_products() {
		return array_values(
			array_map(
				function ( $action ) {
					return $action->get_args()[0];
				},
				$this->pending_jobs( Yotpo_Sync_Jobs::HOOK_PRODUCT )
			)
		);
	}
}
