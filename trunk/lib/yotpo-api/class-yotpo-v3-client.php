<?php

defined( 'ABSPATH' ) || exit;

/**
 * Client for the Yotpo Core API (V3): https://core-api.yotpo.com/reference/welcome
 *
 * - Authenticates with the app key + API secret merchants already saved: the secret is exchanged
 *   for an access token, sent as X-Yotpo-Token, and reused until the API answers 401.
 * - Paces calls to stay under the limit of 5 requests per second per app key.
 */
class Yotpo_V3_Client {

	const BASE_URL     = 'https://api.yotpo.com/core/v3/stores/';
	const TOKEN_OPTION = 'wc_yotpo_v3_token'; // Also written out in uninstall.php: keep both in sync.
	const MIN_INTERVAL = 0.2; // Seconds between calls: 5 requests per second per app key.
	const TIMEOUT      = 15;

	private $app_key;
	private $secret;

	// Shared by every client in this PHP process, so pacing holds across products in one batch.
	private static $last_call = 0.0;

	public function __construct( $app_key, $secret ) {
		$this->app_key = (string) $app_key;
		$this->secret  = (string) $secret;
	}

	/**
	 * A client for the credentials saved in the plugin settings, or null when they are missing.
	 */
	public static function from_settings() {
		$settings = get_option( 'yotpo_settings', wc_yotpo_get_default_settings() );
		if ( empty( $settings['app_key'] ) || empty( $settings['secret'] ) ) {
			return null;
		}
		return new self( $settings['app_key'], $settings['secret'] );
	}

	public function app_key() {
		return $this->app_key;
	}

	/**
	 * Sends one request. $path is relative to the store, e.g. '/products'.
	 *
	 * @return array{code:int, body:array|null, error:string, retry_after:int}
	 *         code 0 = the request never got an HTTP answer (network error or no token).
	 */
	public function request( $method, $path, $body = null, $query = array(), $retry_on_401 = true ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return self::result( 0, null, $token->get_error_message() );
		}

		$url = self::BASE_URL . rawurlencode( $this->app_key ) . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'X-Yotpo-Token' => $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$this->pace();
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return self::result( 0, null, $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		// An expired token: get a new one and try once more.
		if ( 401 === $code && $retry_on_401 ) {
			delete_option( self::TOKEN_OPTION );
			return $this->request( $method, $path, $body, $query, false );
		}

		$result                = self::result( $code, is_array( $decoded ) ? $decoded : null, $code >= 400 ? self::error_message( $decoded, $code ) : '' );
		$result['retry_after'] = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		return $result;
	}

	/**
	 * The cached access token, or a new one exchanged for the API secret.
	 *
	 * @return string|WP_Error
	 */
	private function token() {
		$stored = get_option( self::TOKEN_OPTION );
		if ( is_array( $stored ) && ! empty( $stored['token'] ) && isset( $stored['app_key'] ) && $stored['app_key'] === $this->app_key ) {
			return $stored['token'];
		}

		$this->pace();
		$response = wp_remote_post(
			self::BASE_URL . rawurlencode( $this->app_key ) . '/access_tokens',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'secret' => $this->secret ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $decoded['access_token'] ) ) {
			return new WP_Error( 'yotpo_v3_token', 'Could not get a Yotpo access token (HTTP ' . $code . '): ' . self::error_message( $decoded, $code ) );
		}

		// Not autoloaded: only sync jobs need it.
		update_option( self::TOKEN_OPTION, array( 'app_key' => $this->app_key, 'token' => $decoded['access_token'] ), false );
		return $decoded['access_token'];
	}

	private function pace() {
		$wait = self::MIN_INTERVAL - ( microtime( true ) - self::$last_call );
		if ( $wait > 0 ) {
			usleep( (int) ( $wait * 1000000 ) );
		}
		self::$last_call = microtime( true );
	}

	private static function result( $code, $body, $error ) {
		return array( 'code' => $code, 'body' => $body, 'error' => $error, 'retry_after' => 0 );
	}

	private static function error_message( $decoded, $code ) {
		if ( 406 === $code && ! is_array( $decoded ) ) {
			// Seen from Yotpo's edge when a URL in the request points at localhost or an IP with a port.
			return 'HTTP 406: Yotpo refused the request. This usually means a product or image URL it cannot accept, such as a localhost address';
		}
		if ( is_array( $decoded ) && ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
			$messages = array();
			foreach ( $decoded['errors'] as $error ) {
				if ( isset( $error['message'] ) ) {
					$messages[] = $error['message'];
				}
			}
			if ( $messages ) {
				return implode( '; ', $messages );
			}
		}
		return 'HTTP ' . $code;
	}
}
