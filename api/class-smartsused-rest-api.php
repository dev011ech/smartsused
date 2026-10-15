<?php
/**
 * Authentication endpoints for the SmartSused REST API.
 *
 * Configure the public Google OAuth client ID through the administrator API
 * or SMARTSUSED_GOOGLE_CLIENT_ID in wp-config.php.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers authentication routes and handles their requests.
 */
final class SmartSused_REST_API {

	/**
	 * Register REST API hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_allowed_origins', array( $this, 'filter_rest_allowed_origins' ) );
		add_filter( 'allowed_http_origins', array( $this, 'filter_rest_allowed_origins' ) );
		add_filter( 'rest_allowed_cors_headers', array( $this, 'filter_rest_allowed_headers' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_cors_headers' ), 10, 4 );
	}

	/**
	 * Add configured application domains to the WordPress REST CORS allowlist.
	 *
	 * Define SMARTSUSED_ALLOWED_ORIGINS as an array in wp-config.php to add
	 * production origins, or use the smartsused_rest_allowed_origins filter.
	 *
	 * @param string[] $origins Existing allowed origins.
	 * @return string[]
	 */
	public function filter_rest_allowed_origins( $origins ) {
		$allowed_origins = array(
			'http://localhost:5173',
			'http://127.0.0.1:5173',
		);

		if ( defined( 'SMARTSUSED_ALLOWED_ORIGINS' ) && is_array( SMARTSUSED_ALLOWED_ORIGINS ) ) {
			$allowed_origins = array_merge( $allowed_origins, SMARTSUSED_ALLOWED_ORIGINS );
		}

		$allowed_origins = apply_filters( 'smartsused_rest_allowed_origins', $allowed_origins );

		if ( ! is_array( $allowed_origins ) ) {
			$allowed_origins = array();
		}

		$allowed_origins = array_filter(
			$allowed_origins,
			static function ( $origin ) {
				if ( ! is_string( $origin ) ) {
					return false;
				}

				$parts = wp_parse_url( $origin );

				return is_array( $parts )
					&& isset( $parts['scheme'], $parts['host'] )
					&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
					&& ! isset( $parts['user'], $parts['pass'], $parts['query'], $parts['fragment'] )
					&& ( ! isset( $parts['path'] ) || '' === $parts['path'] || '/' === $parts['path'] );
			}
		);

		return array_values( array_unique( array_merge( (array) $origins, $allowed_origins ) ) );
	}

	/**
	 * Allow the headers used by bearer-token clients.
	 *
	 * @param string[] $headers Existing REST CORS headers.
	 * @return string[]
	 */
	public function filter_rest_allowed_headers( $headers ) {
		return array_values(
			array_unique(
				array_merge(
					(array) $headers,
					array( 'Authorization', 'Content-Type', 'X-WP-Nonce' )
				)
			)
		);
	}

	/**
	 * Add CORS headers for allowlisted origins and terminate preflight requests.
	 *
	 * @param bool            $served  Whether the response has already been served.
	 * @param WP_HTTP_Response $result REST response.
	 * @param WP_REST_Request  $request REST request.
	 * @param WP_REST_Server   $server REST server.
	 * @return bool
	 */
	public function serve_cors_headers( $served, $result, $request, $server ) {
		unset( $result, $server );

		$origin = get_http_origin();

		if ( ! $origin || ! in_array( $origin, $this->filter_rest_allowed_origins( array() ), true ) ) {
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
		header( 'Vary: Origin', false );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
		header( 'Access-Control-Max-Age: 600' );

		if ( 'OPTIONS' === strtoupper( $request->get_method() ) ) {
			status_header( 204 );
			return true;
		}

		return $served;
	}

	/**
	 * Register authentication endpoints.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/auth/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/auth/google',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'google_login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id_token' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/modules/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_module_status' ),
				'permission_callback' => '__return_true',
			)
		);

		$additional_endpoints = apply_filters( 'smartsused_register_api_endpoints', array() );

		if ( ! is_array( $additional_endpoints ) ) {
			return;
		}

		foreach ( $additional_endpoints as $endpoint ) {
			if (
				! is_array( $endpoint )
				|| empty( $endpoint['route'] )
				|| ! is_string( $endpoint['route'] )
				|| empty( $endpoint['methods'] )
				|| ( ! is_string( $endpoint['methods'] ) && ! is_array( $endpoint['methods'] ) )
				|| empty( $endpoint['callback'] )
				|| empty( $endpoint['permission_callback'] )
				|| ! is_callable( $endpoint['callback'] )
				|| ! is_callable( $endpoint['permission_callback'] )
			) {
				continue;
			}

			$route = ltrim( $endpoint['route'], '/' );
			unset( $endpoint['route'] );

			register_rest_route( 'smartsused/v1', '/' . $route, $endpoint );
		}
	}

	/**
	 * Return the active status of supported add-on plugins.
	 *
	 * @return WP_REST_Response
	 */
	public function get_module_status() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return new WP_REST_Response(
			array(
				'communication' => is_plugin_active( 'smartsused-communication/smartsused-communication.php' ),
				'helpdesk'      => is_plugin_active( 'smartsused-helpdesk/smartsused-helpdesk.php' ),
				'meters'        => is_plugin_active( 'smartsused-meters/smartsused-meters.php' ),
				'polls'         => is_plugin_active( 'smartsused-polls/smartsused-polls.php' ),
				'core_version'  => SMARTSUSED_CORE_VERSION,
			),
			200
		);
	}

	/**
	 * Authenticate a WordPress user by email and password.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( $request ) {
		$email    = sanitize_email( $request->get_param( 'email' ) );
		$password = $request->get_param( 'password' );

		if ( ! is_email( $email ) || ! is_string( $password ) || '' === $password ) {
			return new WP_Error(
				'smartsused_invalid_credentials',
				__( 'Nesprávny e-mail alebo heslo.', 'smartsused-core' ),
				array( 'status' => 401 )
			);
		}

		$user = wp_authenticate( $email, $password );

		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'smartsused_invalid_credentials',
				__( 'Nesprávny e-mail alebo heslo.', 'smartsused-core' ),
				array( 'status' => 401 )
			);
		}

		return $this->create_auth_response( $user );
	}

	/**
	 * Authenticate an existing WordPress user using a verified Google ID token.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function google_login( $request ) {
		$id_token = $request->get_param( 'id_token' );

		if ( ! is_string( $id_token ) || '' === trim( $id_token ) ) {
			return new WP_Error(
				'smartsused_invalid_google_token',
				__( 'Google token je neplatný.', 'smartsused-core' ),
				array( 'status' => 401 )
			);
		}

		$token_info = self::verify_google_identity( $id_token );

		if ( is_wp_error( $token_info ) ) {
			return $token_info;
		}

		$user_id = email_exists( sanitize_email( $token_info['email'] ) );

		if ( ! $user_id ) {
			return new WP_Error(
				'smartsused_google_registration_disabled',
				__( 'Prístup zamietnutý. Váš e-mail nie je registrovaný v systéme správcu.', 'smartsused-core' ),
				array( 'status' => 403 )
			);
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'smartsused_user_not_found',
				__( 'Používateľ sa nenašiel.', 'smartsused-core' ),
				array( 'status' => 404 )
			);
		}

		return $this->create_auth_response( $user );
	}

	/**
	 * Verify the Google ID token signature, audience, issuer, expiry and email.
	 *
	 * @param string $id_token Google ID token.
	 * @return array|WP_Error
	 */
	public static function verify_google_identity( $id_token ) {
		$client_id = SmartSused_Google_Config::get_client_id();

		if (
			! SmartSused_Google_Config::is_valid_client_id( $client_id )
		) {
			return new WP_Error(
				'smartsused_google_not_configured',
				__( 'Prihlásenie cez Google nie je nakonfigurované.', 'smartsused-core' ),
				array( 'status' => 503 )
			);
		}

		if ( ! is_string( $id_token ) || '' === trim( $id_token ) || strlen( $id_token ) > 8192 ) {
			return new WP_Error(
				'smartsused_invalid_google_token',
				__( 'Google token je neplatný.', 'smartsused-core' ),
				array( 'status' => 401 )
			);
		}

		$token_info = self::get_google_token_info( $id_token );

		if ( is_wp_error( $token_info ) ) {
			return $token_info;
		}

		if (
			! isset( $token_info['aud'], $token_info['exp'], $token_info['email'], $token_info['email_verified'] )
			|| $client_id !== $token_info['aud']
			|| ! isset( $token_info['iss'] )
			|| ! in_array( $token_info['iss'], array( 'accounts.google.com', 'https://accounts.google.com' ), true )
			|| ! is_numeric( $token_info['exp'] )
			|| (int) $token_info['exp'] <= time()
			|| ! in_array( $token_info['email_verified'], array( true, 'true' ), true )
			|| ! is_email( $token_info['email'] )
		) {
			return new WP_Error(
				'smartsused_invalid_google_token',
				__( 'Google token je neplatný alebo nebol vystavený pre túto aplikáciu.', 'smartsused-core' ),
				array( 'status' => 401 )
			);
		}

		return $token_info;
	}

	/**
	 * Fetch and decode Google's tokeninfo response.
	 *
	 * @param string $id_token Google ID token.
	 * @return array|WP_Error
	 */
	private static function get_google_token_info( $id_token ) {
		$url      = add_query_arg(
			'id_token',
			$id_token,
			'https://oauth2.googleapis.com/tokeninfo'
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 16384,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'smartsused_google_verification_failed',
				__( 'Google token sa nepodarilo overiť.', 'smartsused-core' ),
				array( 'status' => 502 )
			);
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error(
				'smartsused_invalid_google_token',
				__( 'Google token je neplatný.', 'smartsused-core' ),
				array( 'status' => 401 )
			);
		}

		$token_info = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $token_info ) ) {
			return new WP_Error(
				'smartsused_google_verification_failed',
				__( 'Odpoveď služby Google sa nepodarilo spracovať.', 'smartsused-core' ),
				array( 'status' => 502 )
			);
		}

		return $token_info;
	}

	/**
	 * Generate a JWT and return the authenticated user's details.
	 *
	 * @param WP_User $user Authenticated user.
	 * @return WP_REST_Response|WP_Error
	 */
	private function create_auth_response( $user ) {
		$jwt = smartsused_generate_jwt( $user->ID );

		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		if ( ! is_string( $jwt ) || '' === $jwt ) {
			return new WP_Error(
				'smartsused_jwt_generation_failed',
				__( 'Prihlasovací token sa nepodarilo vytvoriť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$roles = array_values( array_map( 'sanitize_key', $user->roles ) );

		return new WP_REST_Response(
			array(
				'token'        => $jwt,
				'user_id'      => (int) $user->ID,
				'display_name' => $user->display_name,
				'role'         => isset( $roles[0] ) ? $roles[0] : '',
				'roles'        => $roles,
			),
			200
		);
	}
}
