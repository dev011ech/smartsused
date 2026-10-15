<?php
/**
 * Google Sign-In client configuration.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the public Google OAuth client ID without exposing client secrets.
 */
final class SmartSused_Google_Config {

	/**
	 * Default public web client ID.
	 *
	 * @var string
	 */
	const DEFAULT_CLIENT_ID = '447140280262-lbdqms76gfnrsf30vvu9ee53i051b8c9.apps.googleusercontent.com';

	/**
	 * Return the configured public client ID.
	 *
	 * A wp-config.php constant takes precedence over the database setting.
	 *
	 * @return string
	 */
	public static function get_client_id() {
		if ( defined( 'SMARTSUSED_GOOGLE_CLIENT_ID' ) ) {
			return is_string( SMARTSUSED_GOOGLE_CLIENT_ID ) ? trim( SMARTSUSED_GOOGLE_CLIENT_ID ) : '';
		}

		$client_id = get_option( 'smartsused_google_client_id', self::DEFAULT_CLIENT_ID );

		return is_string( $client_id ) ? trim( $client_id ) : '';
	}

	/**
	 * Return where the active client ID is configured.
	 *
	 * @return string
	 */
	public static function get_source() {
		if ( defined( 'SMARTSUSED_GOOGLE_CLIENT_ID' ) ) {
			return 'wp-config';
		}

		return false === get_option( 'smartsused_google_client_id', false ) ? 'plugin-default' : 'database';
	}

	/**
	 * Return the public site origin used by Google Identity Services.
	 *
	 * @return string
	 */
	public static function get_site_origin() {
		$parts = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin;
	}

	/**
	 * Validate the shape of a Google OAuth web client ID.
	 *
	 * @param string $client_id Client ID.
	 * @return bool
	 */
	public static function is_valid_client_id( $client_id ) {
		return is_string( $client_id )
			&& 1 === preg_match( '/\A[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com\z/', $client_id );
	}
}
