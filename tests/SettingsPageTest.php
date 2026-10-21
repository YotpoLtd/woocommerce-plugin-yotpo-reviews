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

	public function set_up() {
		parent::set_up();
		$this->set_setting( 'v3_widgets_ids', self::WIDGET_IDS );
		// Saving with v3 widgets fetches the widget IDs from Yotpo's V1 API.
		$this->route( 'POST', '#^https://api\.yotpo\.com/oauth/token$#', function () {
			return $this->respond( 200, array( 'access_token' => 'v1-token' ) );
		} );
		$this->route( 'GET', '#^https://api\.yotpo\.com/api/v2/widgets$#', function () {
			$names     = array( 'reviews_widget' => 'ReviewsMainWidget', 'qna' => 'QuestionsAndAnswers', 'star_rating' => 'ReviewsStarRatingsWidget', 'reviews_carousel' => 'ReviewsCarousel', 'promoted_products' => 'PromotedProducts', 'reviews_tab' => 'ReviewsTab' );
			$instances = array();
			foreach ( self::WIDGET_IDS as $field => $id ) {
				$instances[] = array( 'widget_type_name' => $names[ $field ], 'widget_instance_id' => $id );
			}
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
		foreach ( array( 'yotpo_language_as_site', 'disable_native_review_system', 'product_sync_enabled', 'product_sync_tags', 'order_sync_enabled' ) as $key ) {
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

	public function test_saved_secret_is_masked_and_shown_while_being_entered() {
		$this->assertMatchesRegularExpression( '#<input type="password" class="yotpo-input yotpo-input--mono" id="secret" name="yotpo_oauth_token" value="test-secret" readonly autocomplete="off" />#', $this->render() );

		$_POST['log_in_button'] = 'Enter your keys';
		$this->assertMatchesRegularExpression( '#<input type="text" class="yotpo-input yotpo-input--mono" id="secret" name="yotpo_oauth_token" value="test-secret"\s+autocomplete="off" />#', $this->render() );
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

	public function test_registration_screen_keeps_its_fields() {
		ob_start();
		wc_display_yotpo_register();
		$html = ob_get_clean();
		foreach ( array( 'yotpo_user_email', 'yotpo_user_name', 'yotpo_user_password', 'yotpo_user_confirm_password', 'yotpo_register', 'log_in_button' ) as $name ) {
			$this->assertStringContainsString( 'name="' . $name . '"', $html );
		}
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
