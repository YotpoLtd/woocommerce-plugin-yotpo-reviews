<?php

defined( 'ABSPATH' ) || exit;

/**
 * The instance IDs of the store's v3 widgets, read when the settings are saved with v3 widgets and when
 * a store switches to them. The Core API has no widget endpoint, so this calls api.yotpo.com/api/v2/widgets,
 * which takes the Core API's access token as its utoken (Yotpo_V3_Client::access_token()). The plugin
 * makes no other call outside the Core API, and no longer logs in to Yotpo's legacy API.
 */
class Yotpo {

	const TIMEOUT     = 5;
	const WIDGETS_URL = 'https://api.yotpo.com/api/v2/widgets';

	private $app_key;
	private $secret;

	/** @var string Why the last request failed, or '' when it didn't. */
	public $error = '';

	public function __construct( $app_key = null, $secret = null ) {
		$this->app_key = (string) $app_key;
		$this->secret  = (string) $secret;
	}

	/**
	 * The store's widget instances. A refused token (it expired, or the secret changed while the app key
	 * stayed the same) is replaced once.
	 *
	 * @return array|false The decoded answer, or false when the token or the request failed ($error says why).
	 */
	public function get_widget_instances() {
		$this->error = '';
		if ( '' === $this->app_key || '' === $this->secret ) {
			$this->error = 'The app key or secret token is missing';
			return false;
		}
		$client = new Yotpo_V3_Client( $this->app_key, $this->secret );

		// First with the cached token; if Yotpo refuses it, once more with a new one.
		$response = $this->fetch_widgets( $client, false );
		if ( is_array( $response ) && 401 === $response['code'] ) {
			$response = $this->fetch_widgets( $client, true );
		}
		if ( false === $response ) {
			return false;
		}
		if ( $response['code'] < 200 || $response['code'] >= 300 ) {
			$this->error = 'Yotpo answered the widgets request with HTTP ' . $response['code'];
			return false;
		}
		$decoded = json_decode( $response['body'], true );
		return is_array( $decoded ) ? $decoded : false;
	}

	/**
	 * One widgets request with the access token (a new one when $fresh).
	 *
	 * @return array{code:int, body:string}|false The answer, or false when there was no token or no answer ($error says why).
	 */
	private function fetch_widgets( Yotpo_V3_Client $client, $fresh ) {
		$token = $client->access_token( $fresh );
		if ( is_wp_error( $token ) ) {
			$this->error = $token->get_error_message();
			return false;
		}
		$response = wp_remote_get(
			add_query_arg( array( 'app_key' => rawurlencode( $this->app_key ), 'utoken' => rawurlencode( $token ) ), self::WIDGETS_URL ),
			array(
				'timeout'   => self::TIMEOUT,
				'headers'   => array( 'User-Agent' => 'Yotpo-Php' ),
				// Verify TLS certificates (WordPress ships its own CA bundle): the request carries an access token.
				'sslverify' => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->error = $response->get_error_message();
			return false;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}
}
