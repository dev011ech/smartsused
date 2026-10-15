<?php
/**
 * Retrieves and caches the licensed JWT signing secret.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the JWT secret only when the installation is online-licensed.
 *
 * The JWT provider must call this function when signing and verifying tokens.
 * Authentication endpoints also call it before issuing a token.
 *
 * @param bool $force_refresh Force a new central activation request.
 * @return string|WP_Error
 */
function smartsused_get_jwt_secret( $force_refresh = false ) {
	$http_host   = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] )
		? wp_unslash( $_SERVER['HTTP_HOST'] )
		: '';
	$site_host   = wp_parse_url( site_url(), PHP_URL_HOST );

	foreach ( array( $http_host, $site_host ) as $host_value ) {
		if ( ! is_string( $host_value ) || '' === $host_value ) {
			continue;
		}

		$normalized_hosts = array( strtolower( trim( $host_value ) ) );
		$parsed_host      = wp_parse_url(
			false === strpos( $host_value, '://' ) ? 'http://' . $host_value : $host_value,
			PHP_URL_HOST
		);

		if ( is_string( $parsed_host ) && '' !== $parsed_host ) {
			$normalized_hosts[] = strtolower( $parsed_host );
		}

		foreach ( $normalized_hosts as $host ) {
			foreach ( array( 'localhost', '127.0.0.1', '::1', '.local', '.test', '.dev' ) as $local_marker ) {
				if ( false !== strpos( $host, $local_marker ) ) {
					return new WP_Error(
						'smartsused_local_environment_forbidden',
						__( 'Aplikácia SmartSused nie je povolená v lokálnom prostredí (localhost).', 'smartsused-core' ),
						array( 'status' => 403 )
					);
				}
			}
		}
	}

	$domain = is_string( $site_host ) ? strtolower( trim( $site_host ) ) : '';
	if ( 0 === strpos( $domain, 'www.' ) ) {
		$domain = substr( $domain, 4 );
	}

	if ( '' === $domain || false === strpos( $domain, '.' ) ) {
		return new WP_Error(
			'smartsused_license_domain_invalid',
			__( 'Doménu webu sa nepodarilo bezpečne určiť.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}

	$license_key = get_option( 'smartsused_license_key', '' );

	if ( ! is_string( $license_key ) || '' === trim( $license_key ) || strlen( $license_key ) > 256 ) {
		return new WP_Error(
			'smartsused_license_key_missing',
			__( 'Zadajte licenčný kľúč v nastaveniach SmartSused Core.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}

	$secret = get_option( 'smartsused_jwt_secret', '' );
	$expires_at = get_option( 'smartsused_jwt_secret_expires_at', '' );
	$cached_license_data = get_option( 'smartsused_license_data', array() );
	$cached_expiration = is_string( $expires_at )
		? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $expires_at, wp_timezone() )
		: false;

	if (
		! $force_refresh
		&& is_string( $secret )
		&& '' !== trim( $secret )
		&& is_string( $expires_at )
		&& '' !== $expires_at
		&& $cached_expiration instanceof DateTimeImmutable
		&& $cached_expiration->format( 'Y-m-d H:i:s' ) === $expires_at
		&& $cached_expiration->getTimestamp() > time()
		&& is_array( $cached_license_data )
		&& isset( $cached_license_data['status'], $cached_license_data['expires_at'], $cached_license_data['modules'] )
		&& 'active' === $cached_license_data['status']
		&& $expires_at === $cached_license_data['expires_at']
		&& $secret === $cached_license_data['jwt_secret']
		&& is_array( $cached_license_data['modules'] )
		&& isset(
			$cached_license_data['modules']['notices'],
			$cached_license_data['modules']['helpdesk'],
			$cached_license_data['modules']['revision'],
			$cached_license_data['modules']['polls']
		)
	) {
		return $secret;
	}

	$body = wp_json_encode(
		array(
			'domain'      => $domain,
			'license_key' => trim( $license_key ),
		)
	);

	if ( ! is_string( $body ) ) {
		return new WP_Error(
			'smartsused_license_request_invalid',
			__( 'Požiadavku na overenie licencie sa nepodarilo pripraviť.', 'smartsused-core' ),
			array( 'status' => 500 )
		);
	}

	$response = wp_remote_post(
		'https://licencia.chudy.online/wp-json/smartsused-license/v1/activate',
		array(
			'timeout'             => 10,
			'redirection'         => 0,
			'blocking'            => true,
			'sslverify'           => true,
			'limit_response_size'  => 16384,
			'headers'             => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json; charset=utf-8',
			),
			'body'                => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'smartsused_license_server_unavailable',
			__( 'Na overenie licencie je potrebné aktívne internetové pripojenie. Prihlásenie je zablokované.', 'smartsused-core' ),
			array( 'status' => 503 )
		);
	}

	$http_status = (int) wp_remote_retrieve_response_code( $response );
	$license_data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 403 === $http_status ) {
		$message = is_array( $license_data )
			&& isset( $license_data['message'] )
			&& is_string( $license_data['message'] )
			? sanitize_text_field( $license_data['message'] )
			: '';

		if ( '' === $message ) {
			$message = __( 'Aplikácia nie je licencovaná pre túto doménu. Kontaktujte poskytovateľa SmartSused.', 'smartsused-core' );
		}

		return new WP_Error(
			'smartsused_license_unauthorized',
			$message,
			array( 'status' => 403 )
		);
	}

	if ( ! is_array( $license_data ) ) {
		return new WP_Error(
			'smartsused_license_response_invalid',
			__( 'Licenčný server vrátil neplatnú odpoveď. Prihlásenie je zablokované.', 'smartsused-core' ),
			array( 'status' => 503 )
		);
	}

	$license_status = isset( $license_data['status'] ) && is_string( $license_data['status'] )
		? strtolower( $license_data['status'] )
		: '';

	if ( 'unauthorized' === $license_status ) {
		$message = isset( $license_data['message'] ) && is_string( $license_data['message'] )
			? sanitize_text_field( $license_data['message'] )
			: '';

		if ( '' === $message ) {
			$message = __( 'Aplikácia nie je licencovaná pre túto doménu. Kontaktujte poskytovateľa SmartSused.', 'smartsused-core' );
		}

		return new WP_Error(
			'smartsused_license_unauthorized',
			$message,
			array( 'status' => 403 )
		);
	}

	if ( 200 !== $http_status || 'active' !== $license_status ) {
		return new WP_Error(
			'smartsused_license_inactive',
			__( 'Aplikácia nie je licencovaná pre túto doménu. Kontaktujte poskytovateľa SmartSused.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}

	if (
		! isset( $license_data['jwt_secret'] )
		|| ! is_string( $license_data['jwt_secret'] )
		|| '' === trim( $license_data['jwt_secret'] )
		|| strlen( $license_data['jwt_secret'] ) > 4096
		|| ! isset( $license_data['expires_at'] )
		|| ! is_string( $license_data['expires_at'] )
	) {
		return new WP_Error(
			'smartsused_license_secret_invalid',
			__( 'Licenčný server neposkytol platný podpisovací kľúč. Prihlásenie je zablokované.', 'smartsused-core' ),
			array( 'status' => 503 )
		);
	}

	$expiration = DateTimeImmutable::createFromFormat(
		'!Y-m-d H:i:s',
		$license_data['expires_at'],
		wp_timezone()
	);

	if (
		! ( $expiration instanceof DateTimeImmutable )
		|| $expiration->format( 'Y-m-d H:i:s' ) !== $license_data['expires_at']
		|| $expiration->getTimestamp() <= time()
	) {
		return new WP_Error(
			'smartsused_license_expired',
			__( 'Platnosť licencie vypršala. Kontaktujte poskytovateľa SmartSused.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}

	$secret = $license_data['jwt_secret'];
	update_option( 'smartsused_jwt_secret', $secret, false );
	update_option( 'smartsused_jwt_secret_expires_at', $license_data['expires_at'], false );

	$modules = array();
	foreach ( array( 'notices', 'helpdesk', 'revision', 'polls' ) as $module_name ) {
		$module_data = isset( $license_data['modules'][ $module_name ] ) && is_array( $license_data['modules'][ $module_name ] )
			? $license_data['modules'][ $module_name ]
			: array();
		$module_secret = isset( $module_data['secret'] ) && is_string( $module_data['secret'] )
			? trim( $module_data['secret'] )
			: '';
		$module_enabled = isset( $module_data['enabled'] )
			&& true === $module_data['enabled']
			&& strlen( $module_secret ) >= 32
			&& strlen( $module_secret ) <= 4096;

		$modules[ $module_name ] = array(
			'enabled' => $module_enabled,
			'secret'  => $module_enabled ? $module_secret : null,
		);
	}

	$cached_license_data = array(
		'status'     => 'active',
		'jwt_secret' => $secret,
		'modules'    => $modules,
		'expires_at' => $license_data['expires_at'],
	);
	update_option( 'smartsused_license_data', $cached_license_data, false );

	if (
		$secret !== get_option( 'smartsused_jwt_secret', '' )
		|| $license_data['expires_at'] !== get_option( 'smartsused_jwt_secret_expires_at', '' )
		|| $cached_license_data !== get_option( 'smartsused_license_data', array() )
	) {
		return new WP_Error(
			'smartsused_license_secret_save_failed',
			__( 'Licenčné údaje sa nepodarilo bezpečne uložiť.', 'smartsused-core' ),
			array( 'status' => 500 )
		);
	}

	return $secret;
}
