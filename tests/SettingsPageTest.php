<?php
/**
 * The settings page: what it renders, and that saving the form as rendered keeps every setting.
 */
class SettingsPageTest extends Yotpo_Test_Case {

	const WIDGET_IDS = array(
		'reviews_widget'    => '454187',
		'qna'               => '469702',
		'star_rating'       => '484571',
		'reviews_carousel'  => '480530',
		'promoted_products' => '492726',
		'reviews_tab'       => '528840',
	);

	public static function set_up_before_class() {
		parent::set_up_before_class();
		// Loaded only in wp-admin by the plugin.
		require_once dirname( __DIR__ ) . '/trunk/templates/wc-yotpo-settings.php';
		require_once dirname( __DIR__ ) . '/trunk/lib/yotpo-api/Yotpo.php';
	}

	/** @var array The widget instances the fake legacy API returns, by field name. */
	private $instances = self::WIDGET_IDS;

	/** @var bool Whether the fake Core API refuses the app key and secret. */
	private $oauth_fails = false;

	/** @var string The access token the fake Core API issued last; the widgets call accepts only it. */
	private $current_token = 'v3-token-1';

	/** @var bool Whether the fake widgets call refuses every token, even the one just issued. */
	private $widgets_refused = false;

	public function set_up() {
		parent::set_up();
		$this->instances   = self::WIDGET_IDS;
		$this->oauth_fails   = false;
		$this->current_token = 'v3-token-1';
		$this->widgets_refused = false;
		$this->set_setting( 'v3_widgets_ids', self::WIDGET_IDS );
		// Saving with v3 widgets fetches the widget IDs from api.yotpo.com/api/v2/widgets, with the Core
		// API's access token (for any store: the first save of new keys asks for the new store's token).
		$this->route( 'POST', '#(^|/core/v3/stores/[^/]+)/access_tokens$#', function () {
			return $this->oauth_fails ? $this->respond( 401, array( 'errors' => array( array( 'message' => 'invalid secret' ) ) ) ) : $this->respond( 200, array( 'access_token' => $this->current_token ) );
		} );
		$this->route( 'GET', '#^https://api\.yotpo\.com/api/v2/widgets$#', function ( $request ) {
			parse_str( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
			if ( $this->widgets_refused || ! isset( $query['utoken'] ) || $this->current_token !== $query['utoken'] ) {
				return $this->respond( 401, array( 'status' => array( 'code' => 401 ) ) );
			}
			$names     = array( 'reviews_widget' => 'ReviewsMainWidget', 'qna' => 'QuestionsAndAnswers', 'star_rating' => 'ReviewsStarRatingsWidget', 'reviews_carousel' => 'ReviewsCarousel', 'promoted_products' => 'PromotedProducts', 'reviews_tab' => 'ReviewsTab' );
			$instances = array();
			foreach ( $this->instances as $field => $id ) {
				$instances[] = array( 'widget_type_name' => $names[ $field ], 'widget_instance_id' => $id );
			}
			$instances[] = array( 'widget_type_name' => 'ReviewsSeoPage', 'widget_instance_id' => '528395' ); // Not one the plugin shows.
			return $this->respond( 200, array( 'widget_instances' => $instances ) );
		} );
	}

	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	public function test_saving_the_page_unchanged_keeps_every_setting() {
		$this->assert_save_keeps_settings();
	}

	public function test_saving_the_page_with_every_option_flipped_keeps_every_setting() {
		$settings = get_option( 'yotpo_settings' );
		foreach ( array( 'yotpo_language_as_site', 'disable_native_review_system', 'product_sync_enabled', 'product_sync_tags', 'order_sync_enabled', 'widget_language_from_page' ) as $key ) {
			$settings[ $key ] = empty( $settings[ $key ] );
		}
		foreach ( array( 'v3_widgets_enables', 'v2_widgets_enables' ) as $group ) {
			foreach ( $settings[ $group ] as $key => $value ) {
				$settings[ $group ][ $key ] = empty( $value );
			}
		}
		$settings['widget_version']       = 'v3' === $settings['widget_version'] ? 'v2' : 'v3';
		$settings['v2_widget_location']   = 'tab';
		$settings['v3_widget_location']   = 'manual';
		$settings['yotpo_order_status']   = 'wc-processing';
		$settings['language_code']        = 'fr';
		$settings['main_widget_tab_name'] = "Reviews 'quoted' & <b>bold</b>";
		$settings['qna_widget_tab_name']  = 'Questions';
		update_option( 'yotpo_settings', $settings );

		$this->assert_save_keeps_settings();
	}

	public function test_sync_switch_and_status_line() {
		update_option( Yotpo_Sync_State::STATE_OPTION, array( 'app_key' => self::APP_KEY, 'status' => 'complete', 'finished' => 1791810498, 'synced' => 12, 'failed' => 0, 'total' => 12 ) );
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<input type="checkbox" role="switch" class="yotpo-switch" id="product_sync_enabled" name="product_sync_enabled" value="1" checked="checked" />#', $html );
		$this->assertMatchesRegularExpression( '#<div class="yotpo-status yotpo-tone--success">Last full sync: [^<]+12 synced, 0 failed\.</div>#', $html );
		$this->assertStringContainsString( '<label class="yotpo-label" for="product_sync_enabled">', $html, 'wp_kses keeps labels' );

		$this->set_setting( 'product_sync_enabled', false );
		$html = $this->render();
		$this->assertMatchesRegularExpression( '#id="product_sync_enabled" name="product_sync_enabled" value="1"\s*/>#', $html, 'Unchecked when off' );
		$this->assertStringContainsString( '<div class="yotpo-status yotpo-tone--neutral">Off. Products are not sent to Yotpo.</div>', $html );
	}

	public function test_orders_card_has_the_switch_status_line_and_trigger_status() {
		$html   = $this->render();
		$orders = substr( $html, strpos( $html, '>Orders<' ) );
		$orders = substr( $orders, 0, strpos( $orders, '</section>' ) );

		$this->assertMatchesRegularExpression( '#id="order_sync_enabled" name="order_sync_enabled" value="1" checked="checked" />#', $orders );
		$this->assertStringContainsString( '<div class="yotpo-status yotpo-tone--info">On. No orders sent yet.</div>', $orders );
		$this->assertStringContainsString( 'Send review requests when an order reaches', $orders, 'The trigger status moved here' );
		$this->assertMatchesRegularExpression( '#<option value="wc-completed" selected="selected">Completed</option>#', $orders );
		$this->assertStringNotContainsString( 'Reviews and orders', $html );

		$this->set_setting( 'order_sync_enabled', false );
		$this->assertMatchesRegularExpression( '#id="order_sync_enabled" name="order_sync_enabled" value="1"\s*/>#', $this->render(), 'Unchecked when off' );
	}

	public function test_send_past_orders_sits_in_the_orders_card() {
		$order = $this->create_order( array( array( $this->create_product(), 1 ) ) );
		$html  = $this->render();
		$orders = substr( $html, strpos( $html, '>Orders<' ) );
		$orders = substr( $orders, 0, strpos( $orders, '</section>' ) );

		$this->assertMatchesRegularExpression( '#<input type="number" class="yotpo-input yotpo-input--days" id="yotpo_past_orders_days" name="yotpo_past_orders_days" value="90" min="1" max="180" step="1" form="yotpo_past_orders_form"\s*/>#', $orders );
		$this->assertStringContainsString( '<p class="yotpo-help" id="yotpo-past-orders-count">1 order in the last 90 days.</p>', $orders );
		$this->assertSame( 1, substr_count( $html, 'name="yotpo_past_orders"' ), 'Only in the Orders card, not in Tools any more' );
	}

	public function test_send_past_orders_is_its_own_form_so_enter_still_saves_the_settings() {
		$html = $this->render();
		$doc  = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );

		// The form's default button, the one Enter in a field clicks, is the first submit owned by it.
		$first = $xpath->query( '//form[@id="yotpo_settings_form"]//input[@type="submit" and not(@form)]' )->item( 0 );
		$this->assertNotNull( $first );
		$this->assertSame( 'yotpo_settings', $first->getAttribute( 'name' ), 'Save changes is the settings form\'s default button' );

		$button = $xpath->query( '//input[@id="yotpo-past-orders"]' )->item( 0 );
		$this->assertSame( 'yotpo_past_orders_form', $button->getAttribute( 'form' ) );
		$days = $xpath->query( '//input[@id="yotpo_past_orders_days"]' )->item( 0 );
		$this->assertSame( 'yotpo_past_orders_form', $days->getAttribute( 'form' ) );
		$this->assertSame( 1, $xpath->query( '//form[@id="yotpo_past_orders_form" and @method="post"]//input[@name="_wpnonce"]' )->length, 'The form carries the nonce its handler checks' );
	}

	public function test_send_past_orders_starts_a_run_and_saves_nothing_else() {
		$before = get_option( 'yotpo_settings' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST  = array(
			'yotpo_past_orders'      => 'Send past orders',
			'yotpo_past_orders_days' => '30',
			'order_sync_enabled'     => '', // A switch changed but not saved: the button doesn't save it.
			'_wpnonce'               => wp_create_nonce( 'yotpo_settings_form' ),
		);
		$_REQUEST = $_POST;

		ob_start();
		wc_display_yotpo_admin_page();
		$html = ob_get_clean();

		$state = Yotpo_Order_Backfill::state();
		$this->assertSame( Yotpo_Order_Backfill::STATUS_RUNNING, $state['status'] );
		$this->assertSame( 30, $state['days'] );
		$this->assertStringContainsString( 'Sending orders from the last 30 days in the background.', $html );
		$this->assertStringContainsString( 'Sending past orders: 0 of 0 checked.', $html, 'Progress shows under the button' );
		$this->assertMatchesRegularExpression( '#id="yotpo-past-orders" value="Send past orders" class="yotpo-btn yotpo-btn--primary"\s+disabled#', $html, 'One run at a time' );
		$this->assertSame( $before, get_option( 'yotpo_settings' ) );
		$_REQUEST = array();
	}

	public function test_custom_order_status_can_be_the_trigger_and_survives_a_save() {
		add_filter(
			'wc_order_statuses',
			function ( $statuses ) {
				return array_merge( $statuses, array( 'wc-delivered' => 'Delivered' ) );
			}
		);
		$this->set_setting( 'yotpo_order_status', 'wc-delivered' );

		$this->assertMatchesRegularExpression( '#<option value="wc-delivered" selected="selected">Delivered</option>#', $this->render() );
		$this->assert_save_keeps_settings();
	}

	public function test_the_saved_secret_never_reaches_the_page() {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<input type="password" class="yotpo-input yotpo-input--mono" id="secret" name="yotpo_oauth_token" value="\*{24}" readonly autocomplete="new-password" />#', $html, 'A password field, holding a mask' );
		$this->assertStringNotContainsString( 'test-secret', $html );
	}

	public function test_the_secret_is_shown_while_keys_are_being_entered() {
		$this->set_setting( 'app_key', '' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<input type="text" class="yotpo-input yotpo-input--mono" id="secret" name="yotpo_oauth_token" value="test-secret"\s+autocomplete="new-password" />#', $html );
		$this->assertStringNotContainsString( 'yotpo-change-keys', $html, 'The fields are editable already' );
	}

	public function test_saved_keys_are_changed_with_change_keys() {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<input type="text" class="yotpo-input yotpo-input--mono" id="app_key" name="yotpo_app_key" value="test-app-key" readonly />#', $html );
		$this->assertStringContainsString( '<button type="button" id="yotpo-change-keys" class="yotpo-btn yotpo-btn--outline">Change keys</button>', $html );
		$this->assertMatchesRegularExpression( '#<div id="yotpo-change-keys-note" class="yotpo-callout" hidden>A different app key connects this store to another Yotpo account\.#', $html );
		$script = file_get_contents( dirname( __DIR__ ) . '/trunk/assets/js/settings.js' );
		$this->assertStringContainsString( "jQuery('#app_key, #secret').prop('readonly', false);", $script );
		$this->assertStringContainsString( "jQuery('#secret').val('')", $script, 'The mask is cleared before the merchant types' );
	}

	public function test_a_new_secret_for_the_same_app_key_is_saved_and_keeps_the_stored_ids() {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, 111 );

		$this->save_keys( self::APP_KEY, 'rotated-secret' );

		$this->assertSame( 'rotated-secret', get_option( 'yotpo_settings' )['secret'] );
		$this->assertSame( '111', get_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ), 'Same account' );
	}

	public function test_a_different_app_key_needs_its_secret() {
		foreach ( array( '', wc_yotpo_saved_secret_mask() ) as $secret ) {
			$html = $this->save_keys( 'other-app-key', $secret );

			$this->assertSame( self::APP_KEY, get_option( 'yotpo_settings' )['app_key'] );
			$this->assertSame( 'test-secret', get_option( 'yotpo_settings' )['secret'], 'The mask is never saved as the secret' );
			$this->assertStringContainsString( 'To connect a different app key, enter its secret token too. The saved keys were kept.', $html );
		}
	}

	public function test_an_emptied_secret_field_keeps_the_saved_secret() {
		$this->save_keys( self::APP_KEY, '' );

		$this->assertSame( 'test-secret', get_option( 'yotpo_settings' )['secret'] );
	}

	public function test_an_empty_app_key_keeps_the_saved_keys() {
		$html = $this->save_keys( '', 'some-secret' );

		$this->assertSame( self::APP_KEY, get_option( 'yotpo_settings' )['app_key'] );
		$this->assertSame( 'test-secret', get_option( 'yotpo_settings' )['secret'] );
		$this->assertStringContainsString( 'The app key can', $html );
	}

	public function test_new_keys_yotpo_refuses_keep_the_store_on_its_account() {
		$product = $this->create_product();
		update_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, 111 );
		$this->oauth_fails = true;

		$html = $this->save_keys( 'other-app-key', 'wrong-secret' );

		$this->assertSame( self::APP_KEY, get_option( 'yotpo_settings' )['app_key'] );
		$this->assertSame( 'test-secret', get_option( 'yotpo_settings' )['secret'] );
		$this->assertSame( '111', get_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ) );
		$this->assertStringContainsString( 'Could not connect with the new keys, the saved keys were kept: Could not get a Yotpo access token (HTTP 401): invalid secret', $html );
	}

	public function test_new_keys_yotpo_refuses_keep_a_v2_store_on_its_account() {
		// A V2 store doesn't look the widgets up when saving, so the keys are checked on their own.
		$this->set_setting( 'widget_version', 'v2' );
		$product = $this->create_product();
		update_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, 111 );
		$this->oauth_fails = true;

		$html = $this->save_keys( 'other-app-key', 'wrong-secret' );

		$this->assertSame( self::APP_KEY, get_option( 'yotpo_settings' )['app_key'] );
		$this->assertSame( 'test-secret', get_option( 'yotpo_settings' )['secret'] );
		$this->assertSame( '111', get_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_PRODUCT_ID, true ), 'Nothing forgotten' );
		$this->assertStringContainsString( 'Could not connect with the new keys, the saved keys were kept: Could not get a Yotpo access token (HTTP 401): invalid secret', $html );
		$this->assertStringNotContainsString( 'This store is now connected', $html );
	}

	public function test_a_different_account_forgets_what_the_plugin_stored_about_the_old_one() {
		$product = $this->create_product();
		foreach ( array( Yotpo_Catalog_Api::META_PRODUCT_ID, Yotpo_Catalog_Api::META_VARIANT_ID, Yotpo_Catalog_Api::META_PRODUCT_HASH, Yotpo_Catalog_Api::META_VARIANT_HASH, Yotpo_Catalog_Api::META_BLOCKLISTED ) as $key ) {
			update_post_meta( $product->get_id(), $key, '1' );
		}
		$order = $this->create_order( array( array( $product, 1 ) ) );
		$order->update_meta_data( Yotpo_Order_Api::META_ORDER_ID, 123 );
		$order->update_meta_data( Yotpo_Order_Api::META_UPDATE_HASH, 'x' );
		$order->save_meta_data();
		set_transient( Yotpo_Order_Api::ABSENT_PREFIX . $order->get_id(), 1, 600 );
		set_transient( Yotpo_Order_Backfill::SETTLED_PREFIX . $order->get_id() . '_1', 1, DAY_IN_SECONDS );
		update_option( Yotpo_Sync_State::STATE_OPTION, array( 'status' => 'complete', 'app_key' => self::APP_KEY ) );
		update_option( Yotpo_Order_Sync::STATS_OPTION, array( 'last_sent' => time() - 60, 'enabled_at' => time() - 30 * DAY_IN_SECONDS ) );
		$this->assertTrue( Yotpo_Order_Backfill::start( 30 ), 'A Send past orders run for the old account' );
		Yotpo_Order_Backfill::maybe_schedule_sweep();
		Yotpo_Product_Sync::start_full_sync();

		$html = $this->save_keys( 'other-app-key', 'other-secret' );

		$settings = get_option( 'yotpo_settings' );
		$this->assertSame( array( 'other-app-key', 'other-secret' ), array( $settings['app_key'], $settings['secret'] ) );
		$this->assertStringContainsString( 'This store is now connected to the Yotpo account with app key other-app-key.', $html );
		foreach ( Yotpo_Account_Switch::META_KEYS as $key ) {
			$this->assertSame( '', get_post_meta( $product->get_id(), $key, true ), "Product meta $key kept" );
		}
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( Yotpo_Order_Api::META_ORDER_ID ), 'Send past orders and the sweep can send the order to the new account' );
		$this->assertSame( '', $order->get_meta( Yotpo_Order_Api::META_UPDATE_HASH ) );
		$this->assertFalse( get_transient( Yotpo_Order_Api::ABSENT_PREFIX . $order->get_id() ) );
		$this->assertFalse( get_transient( Yotpo_Order_Backfill::SETTLED_PREFIX . $order->get_id() . '_1' ) );
		$this->assertFalse( get_option( Yotpo_Sync_State::STATE_OPTION ), 'The full sync starts again for the new account' );
		$this->assertFalse( get_option( Yotpo_Order_Backfill::STATE_OPTION ) );
		$stats = get_option( Yotpo_Order_Sync::STATS_OPTION );
		$this->assertSame( array( 'enabled_at' ), array_keys( $stats ), 'Counts start over' );
		$this->assertGreaterThanOrEqual( time() - 5, $stats['enabled_at'], 'The daily sweep goes back no further than the switch' );
		$this->assertSame( array(), $this->pending_jobs(), "The old account's product jobs are cancelled" );
		$this->assertSame( array(), $this->pending_jobs( null, Yotpo_Order_Sync::GROUP ) );
	}

	public function test_the_daily_sweep_is_scheduled_again_for_the_new_account() {
		Yotpo_Order_Backfill::maybe_schedule_sweep();
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Order_Backfill::HOOK_SWEEP, Yotpo_Order_Sync::GROUP ) );

		$this->save_keys( 'other-app-key', 'other-secret' );
		$this->assertSame( array(), $this->pending_jobs( Yotpo_Order_Backfill::HOOK_SWEEP, Yotpo_Order_Sync::GROUP ), 'Cancelled with the old account\'s order jobs' );

		Yotpo_Order_Backfill::maybe_schedule_sweep(); // The next admin page load.
		$this->assertCount( 1, $this->pending_jobs( Yotpo_Order_Backfill::HOOK_SWEEP, Yotpo_Order_Sync::GROUP ) );
	}

	public function test_the_full_sync_starts_for_the_new_account() {
		$this->save_keys( 'other-app-key', 'other-secret' );

		Yotpo_Sync_Jobs::maybe_start_initial_sync();

		$this->assertNotEmpty( $this->pending_jobs() );
		$this->assertSame( 'other-app-key', Yotpo_Sync_State::get()['app_key'] );
	}

	public function test_text_values_are_escaped() {
		$this->set_setting( 'main_widget_tab_name', "Reviews' \"x\" <b>" );
		$this->assertMatchesRegularExpression( '#value="Reviews(&\#039;|&apos;) &quot;x&quot; &lt;b&gt;"#', $this->render() );
	}

	public function test_export_buttons_are_disabled_without_keys() {
		$this->set_setting( 'secret', '' );
		$html = $this->render();
		$this->assertMatchesRegularExpression( '#<button type="button" id="yotpo-export-reviews" class="yotpo-btn yotpo-btn--outline"\s+disabled#', $html );
		$this->assertMatchesRegularExpression( '#name="yotpo_past_orders" id="yotpo-past-orders" value="Send past orders" class="yotpo-btn yotpo-btn--primary"\s+disabled#', $html );
	}

	public function test_without_keys_the_settings_ask_for_them_and_link_to_sign_up_on_yotpo() {
		$this->set_setting( 'app_key', '' );
		$this->set_setting( 'secret', '' );

		$html = $this->admin_page();

		$this->assertMatchesRegularExpression( '#<input type="text" class="yotpo-input yotpo-input--mono" id="app_key" name="yotpo_app_key" value=""\s*/>#', $html, 'Editable, not read-only' );
		$this->assertStringContainsString( '<a href="https://accounts.yotpo.com/#/signup" target="_blank" rel="noopener">Sign up on yotpo.com</a>', $html );
		$this->assertStringNotContainsString( 'yotpo_register', $html, 'No sign-up form in the plugin' );
	}

	public function test_v3_stores_choose_between_the_page_language_and_yotpos() {
		$html    = $this->render();
		$widgets = substr( $html, strpos( $html, '>On-site widgets<' ) );
		$widgets = substr( $widgets, 0, strpos( $widgets, '</section>' ) );

		$this->assertMatchesRegularExpression( '#<div id="yotpo-v3-language">.*id="widget_language_from_page" name="widget_language_from_page" value="1" checked="checked"#s', $widgets );
		$this->assertStringContainsString( "Match each page's language", $widgets );
		$this->assertStringContainsString( 'Shows the widgets in the language of each page', $widgets );
		$this->assertStringContainsString( 'Account Settings &gt; General Settings &gt; Language', $widgets );
		$this->assertMatchesRegularExpression( '#<div id="yotpo-v2-language" style="display:none">.*id="yotpo_widget_language_code".*id="yotpo_language_as_site"#s', $widgets, 'The v2 fields are hidden, still in the form' );
		$this->assertStringNotContainsString( '>Language<', $html, 'No Language card of its own' );
		$this->assert_save_keeps_settings();
	}

	public function test_v2_stores_set_the_widget_language_in_the_widgets_card() {
		$this->set_setting( 'widget_version', 'v2' );
		$this->set_setting( 'language_code', 'fr' );

		$html    = $this->render();
		$widgets = substr( $html, strpos( $html, '>On-site widgets<' ) );
		$widgets = substr( $widgets, 0, strpos( $widgets, '</section>' ) );

		$this->assertStringContainsString( '<div id="yotpo-v2-language">', $widgets );
		$this->assertMatchesRegularExpression( '#id="yotpo_widget_language_code" name="yotpo_widget_language_code" value="fr"#', $widgets );
		$this->assertStringNotContainsString( 'V3 widgets show the language set in Yotpo', $widgets );
	}

	public function test_v3_stores_have_no_widget_version_choice() {
		$html = $this->render();

		$this->assertStringContainsString( "<input type=\"hidden\" id=\"yotpo-widget-version\" value=\"v3\" />", $html );
		$this->assertStringNotContainsString( 'name="yotpo_widget_version"', $html );
		$this->assertStringNotContainsString( 'Switch to V3 widgets', $html );
	}

	public function test_v2_stores_keep_v2_with_a_notice_and_a_one_way_switch() {
		$this->set_setting( 'widget_version', 'v2' );

		$html = $this->render();

		$this->assertStringContainsString( 'Your store uses V2 widgets, which are being retired.', $html );
		$this->assertStringNotContainsString( 'next year', $html, 'No end date yet' );
		$this->assertMatchesRegularExpression( '#<input type="submit" form="yotpo_switch_v3_form" name="yotpo_switch_to_v3" id="yotpo-switch-to-v3" value="Switch to V3 widgets"#', $html );
		$this->assertStringContainsString( "<form method=\"post\" id=\"yotpo_switch_v3_form\">", $html );
		$this->assert_save_keeps_settings();
	}

	public function test_saving_cannot_change_the_widget_version() {
		$_POST = $this->submission( $this->render() ) + array( 'yotpo_settings' => 'Save changes', 'yotpo_widget_version' => 'v2' );

		ob_start();
		wc_proccess_yotpo_settings();
		ob_end_clean();

		$this->assertSame( 'v3', get_option( 'yotpo_settings' )['widget_version'] );
	}

	public function test_switching_to_v3_keeps_the_widgets_the_store_showed() {
		$this->set_setting( 'widget_version', 'v2' );
		$this->set_setting( 'v2_widget_location', 'other' );
		$this->set_setting( 'v2_widgets_enables', array( 'qna_product' => false, 'bottom_line_product' => true, 'bottom_line_category' => true ) );
		$this->set_setting( 'v3_widgets_enables', array_fill_keys( array_keys( wc_yotpo_get_default_settings()['v3_widgets_enables'] ), false ) );
		$this->set_setting( 'v3_widgets_ids', array() );

		$html     = $this->switch_to_v3();
		$settings = get_option( 'yotpo_settings' );

		$this->assertSame( 'v3', $settings['widget_version'] );
		$this->assertSame( self::WIDGET_IDS, $settings['v3_widgets_ids'] );
		$enabled = array_keys( array_filter( $settings['v3_widgets_enables'] ) );
		sort( $enabled );
		$this->assertSame( array( 'reviews_widget_product', 'star_rating_category', 'star_rating_product' ), $enabled );
		$this->assertSame( 'manual', $settings['v3_widget_location'], 'v2 "Other" meant placed in the theme' );
		$this->assertStringContainsString( 'Your store now uses V3 widgets.', $html );
		$this->assertStringNotContainsString( 'Switch to V3 widgets', $html, 'No way back' );
	}

	public function test_switching_keeps_v3_widgets_the_merchant_set_before() {
		$this->set_setting( 'widget_version', 'v2' );
		$v3 = array_fill_keys( array_keys( wc_yotpo_get_default_settings()['v3_widgets_enables'] ), false );
		$v3['reviews_carousel_home'] = true;
		$this->set_setting( 'v3_widgets_enables', $v3 );
		$this->set_setting( 'v2_widget_location', 'footer' );
		$this->set_setting( 'v3_widget_location', 'manual' );

		$this->switch_to_v3();

		$this->assertSame( $v3, get_option( 'yotpo_settings' )['v3_widgets_enables'] );
		$this->assertSame( 'manual', get_option( 'yotpo_settings' )['v3_widget_location'], 'The v3 location the merchant chose is kept' );
	}

	public function test_switching_is_refused_without_a_v3_reviews_widget() {
		$this->set_setting( 'widget_version', 'v2' );
		$this->instances = array( 'qna' => '469702' );

		$html = $this->switch_to_v3();

		$this->assertSame( 'v2', get_option( 'yotpo_settings' )['widget_version'] );
		$this->assertStringContainsString( 'Your Yotpo account has no V3 Reviews Widget yet', $html );
		$this->assertStringContainsString( '<a href="https://reviews.yotpo.com/#/display-reviews/on-site-widgets"', $html, 'The link survives the message filter' );
	}

	public function test_switching_is_refused_when_yotpo_refuses_the_keys() {
		$this->set_setting( 'widget_version', 'v2' );
		$this->oauth_fails = true;

		$html = $this->switch_to_v3();

		$this->assertSame( 'v2', get_option( 'yotpo_settings' )['widget_version'] );
		$this->assertStringContainsString( 'Could not fetch the widgets', $html );
	}

	public function test_saving_with_keys_yotpo_refuses_keeps_the_widget_ids_without_a_php_warning() {
		$this->oauth_fails = true;
		$_POST             = $this->submission( $this->render() ) + array( 'yotpo_settings' => 'Save changes' );

		ob_start();
		wc_proccess_yotpo_settings();
		$html = ob_get_clean();

		$this->assertSame( self::WIDGET_IDS, get_option( 'yotpo_settings' )['v3_widgets_ids'] );
		$this->assertStringContainsString( 'Could not get a Yotpo access token (HTTP 401): invalid secret', $html, 'The real cause reaches the admin notice' );
	}

	public function test_the_first_save_of_new_keys_fetches_the_widget_ids_with_those_keys() {
		$this->set_setting( 'app_key', '' );
		$this->set_setting( 'secret', '' );
		$this->set_setting( 'v3_widgets_ids', array() );
		$_POST = array( 'yotpo_app_key' => 'new-app-key', 'yotpo_oauth_token' => 'new-secret', 'yotpo_settings' => 'Save changes' ) + $this->submission( $this->render() );

		ob_start();
		wc_proccess_yotpo_settings();
		$html = ob_get_clean();

		$token_requests = $this->token_requests();
		$this->assertCount( 1, $token_requests );
		$this->assertStringContainsString( '/core/v3/stores/new-app-key/access_tokens', $token_requests[0]['url'], 'The keys being saved, not the stored (empty) ones' );
		$this->assertSame( 'new-secret', $token_requests[0]['body']['secret'] );
		$this->assertSame( self::WIDGET_IDS, get_option( 'yotpo_settings' )['v3_widgets_ids'], 'No second save needed' );
		$this->assertStringNotContainsString( 'Could not get a Yotpo access token', $html );
	}

	public function test_widget_ids_use_the_core_api_token_and_no_legacy_login() {
		$_POST = $this->submission( $this->render() ) + array( 'yotpo_settings' => 'Save changes' );

		ob_start();
		wc_proccess_yotpo_settings();
		ob_end_clean();

		$this->assertSame( self::WIDGET_IDS, get_option( 'yotpo_settings' )['v3_widgets_ids'] );
		$this->assertCount( 1, $this->token_requests() );
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'oauth/token', $request['url'], 'No legacy login' );
		}
	}

	public function test_a_refused_widget_token_is_replaced_once() {
		update_option( Yotpo_V3_Client::TOKEN_OPTION, array( 'app_key' => self::APP_KEY, 'secret_hash' => wp_hash( 'test-secret' ), 'token' => 'expired-token' ) );
		$_POST = $this->submission( $this->render() ) + array( 'yotpo_settings' => 'Save changes' );

		ob_start();
		wc_proccess_yotpo_settings();
		ob_end_clean();

		$this->assertSame( self::WIDGET_IDS, get_option( 'yotpo_settings' )['v3_widgets_ids'] );
		$this->assertCount( 1, $this->token_requests(), 'One new token after the stored one was refused' );
		$widget_calls = array_filter( $this->requests, function ( $request ) {
			return false !== strpos( $request['url'], '/api/v2/widgets' );
		} );
		$this->assertCount( 2, $widget_calls );
	}

	public function test_a_token_cached_for_another_secret_is_not_reused_so_a_wrong_secret_is_caught_on_save() {
		// A token for this app key, issued for a different secret than the one being saved.
		update_option( Yotpo_V3_Client::TOKEN_OPTION, array( 'app_key' => self::APP_KEY, 'secret_hash' => wp_hash( 'old-secret' ), 'token' => $this->current_token ) );
		$this->oauth_fails = true;
		$_POST             = $this->submission( $this->render() ) + array( 'yotpo_settings' => 'Save changes' );

		ob_start();
		wc_proccess_yotpo_settings();
		ob_end_clean();

		$this->assertCount( 1, $this->token_requests(), 'The secret being saved is exchanged, not answered from the cache' );
		$this->assertSame( self::WIDGET_IDS, get_option( 'yotpo_settings' )['v3_widgets_ids'], 'The saved IDs are kept when Yotpo refuses the secret' );
		$widget_calls = array_filter( $this->requests, function ( $request ) {
			return false !== strpos( $request['url'], '/api/v2/widgets' );
		} );
		$this->assertCount( 0, $widget_calls, 'No widget lookup with another secret\'s token' );
	}

	public function test_a_widgets_call_refused_after_the_new_token_reports_its_http_status() {
		$this->widgets_refused = true;
		$_POST = $this->submission( $this->render() ) + array( 'yotpo_settings' => 'Save changes' );

		ob_start();
		wc_proccess_yotpo_settings();
		$html = ob_get_clean();

		$this->assertSame( self::WIDGET_IDS, get_option( 'yotpo_settings' )['v3_widgets_ids'], 'The saved IDs are kept' );
		$this->assertStringContainsString( 'Yotpo answered the widgets request with HTTP 401', $html );
	}

	/** The requests for a Core API access token. */
	/** Saves the settings as rendered, with these keys typed in. Returns the notices. */
	private function save_keys( $app_key, $secret ) {
		$_POST = array( 'yotpo_app_key' => $app_key, 'yotpo_oauth_token' => $secret, 'yotpo_settings' => 'Save changes' ) + $this->submission( $this->render() );
		ob_start();
		wc_proccess_yotpo_settings();
		return ob_get_clean();
	}

	private function token_requests() {
		return array_values(
			array_filter(
				$this->requests,
				function ( $request ) {
					return 'POST' === $request['method'] && preg_match( '#/access_tokens$#', $request['path'] );
				}
			)
		);
	}

	/** The admin page as WordPress shows it, after a POST of $post with the settings nonce. */
	private function admin_page( array $post = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( $post ) {
			$_POST    = $post + array( '_wpnonce' => wp_create_nonce( 'yotpo_settings_form' ) );
			$_REQUEST = $_POST;
		}
		ob_start();
		wc_display_yotpo_admin_page();
		$html     = ob_get_clean();
		$_REQUEST = array();
		return $html;
	}

	private function switch_to_v3() {
		return $this->admin_page( array( 'yotpo_switch_to_v3' => 'Switch to V3 widgets' ) );
	}

	/**
	 * Submits the form as rendered, the way a browser would, and checks the save handler stored exactly
	 * the settings the page showed.
	 */
	private function assert_save_keeps_settings() {
		$before = $this->stored_settings();
		$_POST  = $this->submission( $this->render() ) + array( 'yotpo_settings' => 'Save changes' );

		ob_start();
		wc_proccess_yotpo_settings();
		ob_end_clean();

		// The save handler writes the settings it manages; native_star_ratings_enabled, a key only the
		// defaults have, was never among them (the plugin reads a separate option of that name).
		$after = $this->stored_settings();
		$this->assertSame( array( 'native_star_ratings_enabled' ), array_values( array_diff( array_keys( $before ), array_keys( $after ) ) ) );
		foreach ( $after as $key => $value ) {
			$this->assertEquals( $before[ $key ], $value, "Setting $key changed" );
		}
	}

	/** The settings as stored, without the keys wc_yotpo_fill_missing_settings() adds on every read. */
	private function stored_settings() {
		remove_filter( 'option_yotpo_settings', 'wc_yotpo_fill_missing_settings' );
		$settings = get_option( 'yotpo_settings' );
		add_filter( 'option_yotpo_settings', 'wc_yotpo_fill_missing_settings' );
		return $settings;
	}

	private function render() {
		ob_start();
		wc_display_yotpo_settings();
		return ob_get_clean();
	}

	/** What a browser submits for the settings form: checked checkboxes, selected options, field values. */
	private function submission( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );
		$form  = $xpath->query( '//form[@id="yotpo_settings_form"]' )->item( 0 );
		$this->assertNotNull( $form );
		$post = array();
		foreach ( $xpath->query( './/input|.//select', $form ) as $field ) {
			$name = $field->getAttribute( 'name' );
			if ( '' === $name || $field->hasAttribute( 'disabled' ) ) {
				continue;
			}
			if ( 'select' === $field->nodeName ) {
				$options        = $xpath->query( './/option[@selected]', $field );
				$options        = $options->length ? $options : $xpath->query( './/option', $field );
				$post[ $name ] = $options->item( 0 )->getAttribute( 'value' );
				continue;
			}
			$type = $field->getAttribute( 'type' );
			if ( 'submit' === $type || ( 'checkbox' === $type && ! $field->hasAttribute( 'checked' ) ) ) {
				continue;
			}
			$post[ $name ] = $field->getAttribute( 'value' );
		}
		return $post;
	}
}
