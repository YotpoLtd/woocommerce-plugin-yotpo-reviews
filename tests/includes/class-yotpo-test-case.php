<?php
/**
 * Base class for the plugin's tests.
 *
 * Every test starts with the plugin connected (test app key and secret), "Sync products to Yotpo" on,
 * no Yotpo token cached and no sync jobs queued. Yotpo's API is answered by routes the test declares
 * with route(); any other request fails the call instead of reaching the network.
 */
abstract class Yotpo_Test_Case extends WP_UnitTestCase {

	const APP_KEY = 'test-app-key';
	const API     = 'https://api.yotpo.com/core/v3/stores/' . self::APP_KEY;

	/** @var array[] Each: method, path regex, responder. */
	private $routes = array();

	/** @var array[] Every request the plugin made: method, path, url, body (decoded), token, time. */
	protected $requests = array();

	/** @var int Access tokens handed out by the token route. */
	protected $tokens_issued = 0;

	public function set_up() {
		parent::set_up();
		$this->routes        = array();
		$this->requests      = array();
		$this->tokens_issued = 0;
		update_option(
			'yotpo_settings',
			array_merge(
				wc_yotpo_get_default_settings(),
				array(
					'app_key'              => self::APP_KEY,
					'secret'               => 'test-secret',
					'product_sync_enabled' => true,
				)
			)
		);
		delete_option( 'wc_yotpo_v3_token' );
		delete_option( Yotpo_Sync_State::STATE_OPTION );
		delete_transient( Yotpo_Sync_Jobs::PAUSE_TRANSIENT );
		delete_transient( Yotpo_Sync_Jobs::RECHECK_TRANSIENT );
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );
		add_filter( 'pre_http_request', array( $this, 'answer_request' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'answer_request' ), 10 );
		as_unschedule_all_actions( '', array(), Yotpo_Sync_Jobs::GROUP );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------------------------
	// Yotpo API mock
	// -----------------------------------------------------------------------------------------

	/**
	 * Answers $method requests whose path matches $pattern. For the Core API (V3) the path is the part
	 * after /core/v3/stores/{app_key}, such as /products; for any other URL it is the URL without its query.
	 *
	 * @param callable $responder Receives the request (see $requests) and returns respond(...).
	 */
	protected function route( $method, $pattern, $responder ) {
		$this->routes[] = array( $method, $pattern, $responder );
	}

	/** Answers the token request with a new token each time: tok-1, tok-2, ... */
	protected function route_tokens() {
		$this->route(
			'POST',
			'#^/access_tokens$#',
			function () {
				$this->tokens_issued++;
				return $this->respond( 200, array( 'access_token' => 'tok-' . $this->tokens_issued ) );
			}
		);
	}

	/** Answers lookups by external ID with the given Yotpo product (or none). */
	protected function route_lookup( $yotpo_id = null, $external_id = null ) {
		$this->route(
			'GET',
			'#^/products$#',
			function () use ( $yotpo_id, $external_id ) {
				$products = $yotpo_id ? array( array( 'yotpo_id' => $yotpo_id, 'external_id' => (string) $external_id ) ) : array();
				return $this->respond( 200, array( 'products' => $products, 'pagination' => array( 'next_page_info' => null ) ) );
			}
		);
	}

	protected function respond( $code, $body = null, $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => null === $body ? '' : wp_json_encode( $body ),
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** @internal pre_http_request filter. */
	public function answer_request( $pre, $args, $url ) {
		$method  = isset( $args['method'] ) ? $args['method'] : 'GET';
		$path    = 0 === strpos( $url, self::API ) ? substr( strtok( $url, '?' ), strlen( self::API ) ) : strtok( $url, '?' );
		$request = array(
			'method' => $method,
			'path'   => $path,
			'url'    => $url,
			'body'   => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
			'token'  => isset( $args['headers']['X-Yotpo-Token'] ) ? $args['headers']['X-Yotpo-Token'] : null,
			'time'   => microtime( true ),
		);
		$this->requests[] = $request;
		foreach ( $this->routes as $route ) {
			if ( $route[0] === $method && preg_match( $route[1], $path ) ) {
				return call_user_func( $route[2], $request );
			}
		}
		return new WP_Error( 'yotpo_test_unexpected_request', 'No test route for ' . $method . ' ' . $url );
	}

	/** The requests made so far as "METHOD path". */
	protected function calls() {
		return array_map(
			function ( $request ) {
				return $request['method'] . ' ' . $request['path'];
			},
			$this->requests
		);
	}

	// -----------------------------------------------------------------------------------------
	// Products and jobs
	// -----------------------------------------------------------------------------------------

	/**
	 * A published simple product. $attributes become custom product attributes (brand, upc, ...).
	 */
	protected function create_product( $name = 'Wool Beanie', $sku = 'YT-ACC-001', $price = '18', $attributes = array() ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->set_attributes( $this->attributes( $attributes ) );
		$product->save();
		return $product;
	}

	/**
	 * A published variable product with one variation per size (attribute "Size", each with its own SKU).
	 */
	protected function create_variable_product( $name = 'Hoodie', $sku = 'YT-HOOD-VAR', $sizes = array( 'S', 'M', 'L' ) ) {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( $sizes );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		foreach ( $sizes as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_sku( $sku . '-' . $size );
			$variation->set_regular_price( '50' );
			$variation->set_status( 'publish' );
			$variation->save();
		}
		return wc_get_product( $product->get_id() );
	}

	private function attributes( $values ) {
		$attributes = array();
		foreach ( $values as $name => $value ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_visible( true );
			$attributes[] = $attribute;
		}
		return $attributes;
	}

	/**
	 * An order from Dana Levi (IL) with these lines, each a product (or variation) and a quantity, in
	 * $status. The 1.x order hook is kept out of it: it would call the legacy API.
	 *
	 * @param array[] $lines Each: array( WC_Product $product, int $quantity ).
	 */
	protected function create_order( array $lines, $status = 'completed', array $billing = array() ) {
		remove_action( 'woocommerce_order_status_changed', 'wc_yotpo_map' );
		$order = wc_create_order();
		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$billing = array_merge( array( 'first_name' => 'Dana', 'last_name' => 'Levi', 'email' => 'dana@example.com', 'country' => 'IL' ), $billing );
		foreach ( $billing as $field => $value ) {
			$order->{'set_billing_' . $field}( $value );
		}
		$order->set_payment_method_title( 'Credit card' );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		add_action( 'woocommerce_order_status_changed', 'wc_yotpo_map' );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Refunds these quantities of an order's lines, by line item ID. The refund amount is the lines' price.
	 *
	 * @param array $quantities Quantities by line item ID.
	 */
	protected function refund( $order, array $quantities ) {
		$lines  = array();
		$amount = 0;
		foreach ( $quantities as $item_id => $quantity ) {
			$item             = $order->get_item( $item_id );
			$line_total       = $item->get_total() / $item->get_quantity() * $quantity;
			$lines[ $item_id ] = array( 'qty' => $quantity, 'refund_total' => $line_total );
			$amount          += $line_total;
		}
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $amount, 'line_items' => $lines ) );
		return wc_get_order( $order->get_id() );
	}

	/** Sync jobs waiting to run, optionally only one hook's. */
	protected function pending_jobs( $hook = null ) {
		$query = array(
			'group'    => Yotpo_Sync_Jobs::GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		);
		if ( $hook ) {
			$query['hook'] = $hook;
		}
		return as_get_scheduled_actions( $query );
	}

	/** Runs the queued sync jobs, including the ones they queue, as the Action Scheduler runner would. */
	protected function run_jobs() {
		for ( $round = 0; $round < 20 && $this->pending_jobs(); $round++ ) {
			foreach ( array_keys( $this->pending_jobs() ) as $action_id ) {
				ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'phpunit' );
			}
		}
	}

	protected function set_setting( $key, $value ) {
		$settings         = get_option( 'yotpo_settings' );
		$settings[ $key ] = $value;
		update_option( 'yotpo_settings', $settings );
	}
}
