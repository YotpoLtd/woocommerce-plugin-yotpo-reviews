<?php

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's last call to Yotpo's legacy API (api.yotpo.com): the instance IDs of the store's v3
 * widgets, read when the settings are saved with v3 widgets. The Core API (V3) has no equivalent yet,
 * so this stays until it does (2.0.0 design, open question 4). Orders, products and sign-up no longer
 * use the legacy API.
 */
class Yotpo {

	const TIMEOUT     = 5;
	const BASE_URI    = 'https://api.yotpo.com';
	const WIDGETS_URL = '/api/v2/widgets';

	private $app_key;
	private $secret;

	/** @var string Why the last request failed, or '' when it didn't. */
	public $error = '';

	public function __construct( $app_key = null, $secret = null ) {
		$this->app_key = (string) $app_key;
		$this->secret  = (string) $secret;
	}

	/**
	 * The store's widget instances, with a legacy access token for the app key and secret.
	 *
	 * @return array|false The decoded answer, or false when the token or the request failed ($error says why).
	 */
	public function get_widget_instances() {
		$token = $this->get_oauth_token();
		if ( ! is_array( $token ) || empty( $token['access_token'] ) ) {
			if ( '' === $this->error ) {
				$this->error = 'Yotpo did not accept the app key and secret token';
			}
			return false;
		}
		return $this->request( 'GET', self::WIDGETS_URL, array( 'app_key' => $this->app_key, 'utoken' => $token['access_token'] ) );
	}

	/**
	 * A legacy access token for the app key and secret.
	 *
	 * @return array|false The decoded answer (access_token on success), or false when the request failed.
	 */
	public function get_oauth_token() {
		return $this->request(
			'POST',
			'/oauth/token',
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->app_key,
				'client_secret' => $this->secret,
			)
		);
	}

	/**
	 * @return array|false The decoded JSON answer, or false when the request failed ($error says why).
	 */
	private function request( $method, $path, array $vars ) {
		$this->error = '';
		$url         = self::BASE_URI . $path;
		$args        = array(
			'method'    => $method,
			'timeout'   => self::TIMEOUT,
			'headers'   => array( 'User-Agent' => 'Yotpo-Php' ),
			// Verify TLS certificates (WordPress ships its own CA bundle): these requests carry the app
			// secret and access tokens, so they must not be interceptable.
			'sslverify' => true,
		);
		if ( 'POST' === $method ) {
			$args['body']                    = wp_json_encode( $vars );
			$args['headers']['Content-Type'] = 'application/json';
		} else {
			$url = add_query_arg( array_map( 'rawurlencode', $vars ), $url );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->error = $response->get_error_message();
			return false;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : false;
	}
}
