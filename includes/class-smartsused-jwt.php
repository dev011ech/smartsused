<?php
/**
 * Issues and verifies SmartSused JSON Web Tokens.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 JWT provider for SmartSused REST requests.
 */
final class SmartSused_JWT {

	/**
	 * Token lifetime in seconds.
	 *
	 * @var int
	 */
	const TOKEN_LIFETIME = 12 * HOUR_IN_SECONDS;

	/**
	 * Register REST bearer authentication.
	 */
	public function __construct() {
		add_filter( 'rest_authentication_errors', array( $this, 'authenticate_rest_request' ), 100 );
		add_filter( 'smartsused_verified_jwt_user_id', array( $this, 'get_verified_user_id' ), 10, 2 );
	}

	/**
	 * Generate a signed token for an existing WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|WP_Error
	 */
	public static function generate( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		if ( $user_id < 1 || ! ( $user instanceof WP_User ) ) {
			return new WP_Error(
				'smartsused_jwt_user_invalid',
				__( 'Používateľa pre prihlasovací token sa nepodarilo overiť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$secret = smartsused_get_jwt_secret();

		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		if ( ! is_string( $secret ) || '' === trim( $secret ) ) {
			return new WP_Error(
				'smartsused_jwt_secret_invalid',
				__( 'Podpisovací kľúč pre prihlasovacie tokeny nie je platný.', 'smartsused-core' ),
				array( 'status' => 503 )
			);
		}

		$issued_at         = time();
		$expires_at        = $issued_at + self::TOKEN_LIFETIME;
		$license_expiration = get_option( 'smartsused_jwt_secret_expires_at', '' );
		$license_expiration = is_string( $license_expiration )
			? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $license_expiration, wp_timezone() )
			: false;

		if (
			! ( $license_expiration instanceof DateTimeImmutable )
			|| $license_expiration->format( 'Y-m-d H:i:s' ) !== get_option( 'smartsused_jwt_secret_expires_at', '' )
		) {
			return new WP_Error(
				'smartsused_jwt_license_expiration_invalid',
				__( 'Dátum platnosti licencie pre prihlasovací token nie je platný.', 'smartsused-core' ),
				array( 'status' => 503 )
			);
		}

		$expires_at = min( $expires_at, $license_expiration->getTimestamp() );

		if ( $expires_at <= $issued_at ) {
			return new WP_Error(
				'smartsused_jwt_license_expired',
				__( 'Platnosť licencie neumožňuje vytvoriť prihlasovací token.', 'smartsused-core' ),
				array( 'status' => 403 )
			);
		}

		$header = array(
			'alg' => 'HS256',
			'typ' => 'JWT',
		);
		$payload = array(
			'iss' => untrailingslashit( site_url() ),
			'aud' => 'smartsused',
			'sub' => (string) $user_id,
			'iat' => $issued_at,
			'nbf' => $issued_at,
			'exp' => $expires_at,
		);
		$encoded_header  = self::base64url_encode( wp_json_encode( $header ) );
		$encoded_payload = self::base64url_encode( wp_json_encode( $payload ) );

		if ( false === $encoded_header || false === $encoded_payload ) {
			return new WP_Error(
				'smartsused_jwt_encoding_failed',
				__( 'Prihlasovací token sa nepodarilo bezpečne pripraviť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$signing_input = $encoded_header . '.' . $encoded_payload;
		$signature     = hash_hmac( 'sha256', $signing_input, $secret, true );

		return $signing_input . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Authenticate REST requests that contain a Bearer token.
	 *
	 * @param WP_Error|null|true $result Existing authentication result.
	 * @return WP_Error|null|true
	 */
	public function authenticate_rest_request( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) && is_string( $_SERVER['HTTP_AUTHORIZATION'] )
			? $_SERVER['HTTP_AUTHORIZATION']
			: ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && is_string( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
				? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
				: '' );

		if ( '' === $authorization ) {
			return $result;
		}

		$authorization = trim( wp_unslash( $authorization ) );

		if ( 1 !== preg_match( '/^Bearer\s+([A-Za-z0-9._~-]+)$/i', $authorization, $matches ) ) {
			return self::authentication_error();
		}

		$user_id = self::verify( $matches[1] );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		wp_set_current_user( $user_id );

		return true;
	}

	/**
	 * Return the verified user ID for endpoints that require a JWT identity.
	 *
	 * @param int             $user_id Previously verified user ID.
	 * @param WP_REST_Request $request REST request.
	 * @return int
	 */
	public function get_verified_user_id( $user_id, $request ) {
		$authorization = $request->get_header( 'authorization' );

		if (
			! is_string( $authorization )
			|| 1 !== preg_match( '/^Bearer\s+([A-Za-z0-9._~-]+)$/i', trim( $authorization ), $matches )
		) {
			return is_int( $user_id ) ? $user_id : 0;
		}

		$verified_user_id = self::verify( $matches[1] );

		if ( is_wp_error( $verified_user_id ) || $verified_user_id !== get_current_user_id() ) {
			return 0;
		}

		return $verified_user_id;
	}

	/**
	 * Verify the signature and required claims of a JWT.
	 *
	 * @param string $token JWT string.
	 * @return int|WP_Error
	 */
	public static function verify( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) > 8192 ) {
			return self::authentication_error();
		}

		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) || in_array( '', $parts, true ) ) {
			return self::authentication_error();
		}

		$header_json  = self::base64url_decode( $parts[0] );
		$payload_json = self::base64url_decode( $parts[1] );
		$signature    = self::base64url_decode( $parts[2] );

		if ( false === $header_json || false === $payload_json || false === $signature ) {
			return self::authentication_error();
		}

		$header  = json_decode( $header_json, true );
		$payload = json_decode( $payload_json, true );

		if (
			! is_array( $header )
			|| ! isset( $header['alg'], $header['typ'] )
			|| 'HS256' !== $header['alg']
			|| 'JWT' !== $header['typ']
			|| ! is_array( $payload )
		) {
			return self::authentication_error();
		}

		$secret = smartsused_get_jwt_secret();

		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$expected_signature = hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], $secret, true );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return self::authentication_error();
		}

		$now = time();

		if (
			! isset( $payload['iss'], $payload['aud'], $payload['sub'], $payload['iat'], $payload['nbf'], $payload['exp'] )
			|| ! is_string( $payload['iss'] )
			|| untrailingslashit( site_url() ) !== $payload['iss']
			|| 'smartsused' !== $payload['aud']
			|| ! is_string( $payload['sub'] )
			|| ! ctype_digit( $payload['sub'] )
			|| ! is_int( $payload['iat'] )
			|| ! is_int( $payload['nbf'] )
			|| ! is_int( $payload['exp'] )
			|| $payload['iat'] > $now + MINUTE_IN_SECONDS
			|| $payload['nbf'] > $now
			|| $payload['exp'] <= $now
			|| $payload['exp'] <= $payload['iat']
			|| $payload['exp'] - $payload['iat'] > self::TOKEN_LIFETIME
		) {
			return self::authentication_error();
		}

		$license_expiration = get_option( 'smartsused_jwt_secret_expires_at', '' );
		$license_expiration = is_string( $license_expiration )
			? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $license_expiration, wp_timezone() )
			: false;

		if (
			! ( $license_expiration instanceof DateTimeImmutable )
			|| $license_expiration->format( 'Y-m-d H:i:s' ) !== get_option( 'smartsused_jwt_secret_expires_at', '' )
			|| $payload['exp'] > $license_expiration->getTimestamp()
		) {
			return self::authentication_error();
		}

		$user_id = absint( $payload['sub'] );

		if ( $user_id < 1 || ! get_userdata( $user_id ) instanceof WP_User ) {
			return self::authentication_error();
		}

		return $user_id;
	}

	/**
	 * Return a standard unauthorized response.
	 *
	 * @return WP_Error
	 */
	private static function authentication_error() {
		return new WP_Error(
			'smartsused_jwt_invalid',
			__( 'Prihlasovací token je neplatný alebo jeho platnosť vypršala.', 'smartsused-core' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Encode bytes using unpadded base64url.
	 *
	 * @param string|false $value Value to encode.
	 * @return string|false
	 */
	private static function base64url_encode( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode strict, unpadded base64url.
	 *
	 * @param string $value Encoded value.
	 * @return string|false
	 */
	private static function base64url_decode( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false;
		}

		$padding = strlen( $value ) % 4;

		if ( 1 === $padding ) {
			return false;
		}

		return base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - $padding ) % 4 ), true );
	}
}

if ( ! function_exists( 'smartsused_generate_jwt' ) ) {
	/**
	 * Generate a SmartSused JWT for a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|WP_Error
	 */
	function smartsused_generate_jwt( $user_id ) {
		return SmartSused_JWT::generate( $user_id );
	}
}
