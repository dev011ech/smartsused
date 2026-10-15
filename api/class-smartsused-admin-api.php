<?php
/**
 * Administrator-only REST API for license and property administration.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes administrator operations to the SmartSused React application.
 */
final class SmartSused_Admin_API {

	/**
	 * Register protected administrator routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/admin/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_overview' ),
				'permission_callback' => array( $this, 'permission_for_administrator' ),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/admin/google-auth',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_google_auth_config' ),
					'permission_callback' => array( $this, 'permission_for_administrator' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_google_auth_config' ),
					'permission_callback' => array( $this, 'permission_for_administrator' ),
					'args'                => array(
						'client_id' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/admin/licence',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_licence' ),
					'permission_callback' => array( $this, 'permission_for_administrator' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_licence' ),
					'permission_callback' => array( $this, 'permission_for_administrator' ),
					'args'                => array(
						'license_key' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/admin/houses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_houses' ),
					'permission_callback' => array( $this, 'permission_for_administrator' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_house' ),
					'permission_callback' => array( $this, 'permission_for_administrator' ),
					'args'                => array(
						'title'          => array( 'required' => true, 'type' => 'string' ),
						'address'        => array( 'required' => true, 'type' => 'string' ),
						'house_number'   => array( 'type' => 'string' ),
						'entrance_count' => array( 'type' => 'integer', 'minimum' => 0 ),
						'unit_count'     => array( 'type' => 'integer', 'minimum' => 0 ),
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/admin/houses/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_house' ),
				'permission_callback' => array( $this, 'permission_for_administrator' ),
				'args'                => array(
					'id'             => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
					'title'          => array( 'required' => true, 'type' => 'string' ),
					'address'        => array( 'required' => true, 'type' => 'string' ),
					'house_number'   => array( 'type' => 'string' ),
					'entrance_count' => array( 'type' => 'integer', 'minimum' => 0 ),
					'unit_count'     => array( 'type' => 'integer', 'minimum' => 0 ),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/admin/manager-assignments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_manager_assignments' ),
					'permission_callback' => array( $this, 'permission_for_administrator' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_manager_assignment' ),
					'permission_callback' => array( $this, 'permission_for_administrator' ),
					'args'                => array(
						'manager_id'  => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
						'property_ids' => array( 'required' => true, 'type' => 'array' ),
					),
				),
			)
		);
	}

	/**
	 * Return global administrator dashboard statistics.
	 *
	 * @return WP_REST_Response
	 */
	public function get_overview() {
		$houses   = wp_count_posts( 'smartsused_property' );
		$managers = get_users(
			array(
				'role'   => 'smartsused_manager',
				'fields' => 'ID',
				'number' => -1,
			)
		);
		$license  = $this->get_licence()->get_data();

		return new WP_REST_Response(
			array(
				'core_version'    => SMARTSUSED_CORE_VERSION,
				'published_houses' => isset( $houses->publish ) ? (int) $houses->publish : 0,
				'managers'        => is_array( $managers ) ? count( $managers ) : 0,
				'license_status'  => isset( $license['status'] ) ? $license['status'] : 'inactive',
			),
			200
		);
	}

	/**
	 * Return Google Sign-In configuration without exposing any client secret.
	 *
	 * @return WP_REST_Response
	 */
	public function get_google_auth_config() {
		return new WP_REST_Response(
			array(
				'client_id'    => SmartSused_Google_Config::get_client_id(),
				'source'       => SmartSused_Google_Config::get_source(),
				'site_origin'  => SmartSused_Google_Config::get_site_origin(),
				'can_edit'     => ! defined( 'SMARTSUSED_GOOGLE_CLIENT_ID' ),
				'secret_used'  => false,
			),
			200
		);
	}

	/**
	 * Store a public Google client ID override.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_google_auth_config( $request ) {
		if ( defined( 'SMARTSUSED_GOOGLE_CLIENT_ID' ) ) {
			return new WP_Error(
				'smartsused_google_client_id_locked',
				__( 'Google Client ID je nastavený v konfigurácii servera a nemožno ho meniť v aplikácii.', 'smartsused-core' ),
				array( 'status' => 409 )
			);
		}

		$client_id = $request->get_param( 'client_id' );
		if ( ! is_string( $client_id ) ) {
			$client_id = '';
		}
		$client_id = trim( sanitize_text_field( $client_id ) );
		if ( ! SmartSused_Google_Config::is_valid_client_id( $client_id ) ) {
			return new WP_Error(
				'smartsused_google_client_id_invalid',
				__( 'Zadajte platné Google OAuth Web Client ID.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		update_option( 'smartsused_google_client_id', $client_id, false );

		return $this->get_google_auth_config();
	}

	/**
	 * Require both the administrator role and the WordPress administration capability.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_for_administrator() {
		$user = wp_get_current_user();

		if (
			! is_user_logged_in()
			|| ! current_user_can( 'manage_options' )
			|| ! $user instanceof WP_User
			|| ! in_array( 'administrator', $user->roles, true )
		) {
			return new WP_Error(
				'smartsused_admin_forbidden',
				__( 'Na túto operáciu nemá používateľ oprávnenie.', 'smartsused-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Return the current license status and live entity counts.
	 *
	 * @return WP_REST_Response
	 */
	public function get_licence() {
		$data = get_option( 'smartsused_license_data', array() );
		$data = is_array( $data ) ? $data : array();
		$expires_at = isset( $data['expires_at'] ) && is_string( $data['expires_at'] )
			? $data['expires_at']
			: (string) get_option( 'smartsused_jwt_secret_expires_at', '' );
		$expires = '' !== $expires_at
			? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $expires_at, wp_timezone() )
			: false;
		$is_active = isset( $data['status'] )
			&& 'active' === $data['status']
			&& $expires instanceof DateTimeImmutable
			&& $expires->getTimestamp() > time();

		$property_count = wp_count_posts( 'smartsused_property' );
		$owner_count = count_users();

		return new WP_REST_Response(
			array(
				'status'          => $is_active ? 'active' : 'inactive',
				'expires_at'      => $expires_at,
				'has_key'         => '' !== (string) get_option( 'smartsused_license_key', '' ),
				'property_count'  => isset( $property_count->publish ) ? (int) $property_count->publish : 0,
				'owner_count'     => isset( $owner_count['avail_roles']['smartsused_owner'] )
					? (int) $owner_count['avail_roles']['smartsused_owner']
					: 0,
				'max_properties'  => null,
				'max_owners'      => null,
			),
			200
		);
	}

	/**
	 * Validate a new license key against the central server and persist only on success.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_licence( $request ) {
		$license_key = trim( sanitize_text_field( $request->get_param( 'license_key' ) ) );

		if ( '' === $license_key || strlen( $license_key ) > 256 ) {
			return new WP_Error(
				'smartsused_admin_license_key_invalid',
				__( 'Zadajte platný licenčný kľúč.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$option_names = array(
			'smartsused_license_key',
			'smartsused_jwt_secret',
			'smartsused_jwt_secret_expires_at',
			'smartsused_license_data',
		);
		$previous_options = array();

		foreach ( $option_names as $option_name ) {
			$previous_options[ $option_name ] = get_option( $option_name, null );
		}

		update_option( 'smartsused_license_key', $license_key, false );
		delete_option( 'smartsused_jwt_secret' );
		delete_option( 'smartsused_jwt_secret_expires_at' );
		delete_option( 'smartsused_license_data' );

		$secret = smartsused_get_jwt_secret( true );

		if ( is_wp_error( $secret ) ) {
			foreach ( $previous_options as $option_name => $previous_value ) {
				if ( null === $previous_value ) {
					delete_option( $option_name );
				} else {
					update_option( $option_name, $previous_value, false );
				}
			}

			return $secret;
		}

		return $this->get_licence();
	}

	/**
	 * Return published houses.
	 *
	 * @return WP_REST_Response
	 */
	public function get_houses() {
		$posts = get_posts(
			array(
				'post_type'      => 'smartsused_property',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return new WP_REST_Response(
			array_map( array( $this, 'format_house' ), $posts ),
			200
		);
	}

	/**
	 * Create a published house.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_house( $request ) {
		$title = sanitize_text_field( $request->get_param( 'title' ) );
		$address = sanitize_text_field( $request->get_param( 'address' ) );

		if ( '' === $title || '' === $address ) {
			return new WP_Error(
				'smartsused_admin_house_fields_required',
				__( 'Názov bytového domu a adresa sú povinné.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'smartsused_property',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $address,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! is_int( $post_id ) || $post_id < 1 ) {
			return new WP_Error(
				'smartsused_admin_house_create_failed',
				__( 'Bytový dom sa nepodarilo vytvoriť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		update_post_meta( $post_id, '_smartsused_house_number', sanitize_text_field( (string) $request->get_param( 'house_number' ) ) );
		update_post_meta( $post_id, '_smartsused_entrance_count', absint( $request->get_param( 'entrance_count' ) ) );
		update_post_meta( $post_id, '_smartsused_unit_count', absint( $request->get_param( 'unit_count' ) ) );

		return new WP_REST_Response( $this->format_house( get_post( $post_id ) ), 201 );
	}

	/**
	 * Update a published property and its administrator-managed metadata.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_house( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );
		$title   = sanitize_text_field( $request->get_param( 'title' ) );
		$address = sanitize_text_field( $request->get_param( 'address' ) );

		if ( ! $post instanceof WP_Post || 'smartsused_property' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'smartsused_admin_house_not_found',
				__( 'Bytový dom sa nenašiel.', 'smartsused-core' ),
				array( 'status' => 404 )
			);
		}

		if ( '' === $title || '' === $address ) {
			return new WP_Error(
				'smartsused_admin_house_fields_required',
				__( 'Názov bytového domu a adresa sú povinné.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$updated_id = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $address,
			),
			true
		);

		if ( is_wp_error( $updated_id ) ) {
			return $updated_id;
		}
		if ( (int) $updated_id !== $post_id ) {
			return new WP_Error(
				'smartsused_admin_house_update_failed',
				__( 'Bytový dom sa nepodarilo aktualizovať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( $request->has_param( 'house_number' ) ) {
			update_post_meta( $post_id, '_smartsused_house_number', sanitize_text_field( (string) $request->get_param( 'house_number' ) ) );
		}
		if ( $request->has_param( 'entrance_count' ) ) {
			update_post_meta( $post_id, '_smartsused_entrance_count', absint( $request->get_param( 'entrance_count' ) ) );
		}
		if ( $request->has_param( 'unit_count' ) ) {
			update_post_meta( $post_id, '_smartsused_unit_count', absint( $request->get_param( 'unit_count' ) ) );
		}

		return new WP_REST_Response( $this->format_house( get_post( $post_id ) ), 200 );
	}

	/**
	 * Return managers and their assigned houses.
	 *
	 * @return WP_REST_Response
	 */
	public function get_manager_assignments() {
		$managers = get_users(
			array(
				'role'    => 'smartsused_manager',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
			)
		);
		$houses = $this->get_houses()->get_data();

		return new WP_REST_Response(
			array(
				'managers' => array_map(
					static function ( $manager ) {
						return array(
							'id'          => (int) $manager->ID,
							'display_name' => (string) $manager->display_name,
							'email'       => (string) $manager->user_email,
							'property_ids' => SmartSused_Manager_Scope::get_property_ids( $manager->ID ),
						);
					},
					$managers
				),
				'houses'   => $houses,
			),
			200
		);
	}

	/**
	 * Save all house assignments for one manager.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_manager_assignment( $request ) {
		$manager_id = absint( $request->get_param( 'manager_id' ) );
		$property_ids = $request->get_param( 'property_ids' );
		$manager = $manager_id > 0 ? get_userdata( $manager_id ) : false;

		if ( ! $manager instanceof WP_User || ! in_array( 'smartsused_manager', $manager->roles, true ) ) {
			return new WP_Error(
				'smartsused_admin_manager_invalid',
				__( 'Vybraný účet nemá rolu správcu.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $property_ids ) ) {
			return new WP_Error(
				'smartsused_admin_property_ids_invalid',
				__( 'Zoznam bytových domov nie je platný.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$validated_ids = array();
		foreach ( $property_ids as $property_id ) {
			if ( ! is_scalar( $property_id ) || absint( $property_id ) < 1 ) {
				return new WP_Error(
					'smartsused_admin_property_id_invalid',
					__( 'Zoznam obsahuje neplatný bytový dom.', 'smartsused-core' ),
					array( 'status' => 400 )
				);
			}
			$validated_ids[] = absint( $property_id );
		}

		$validated_ids = array_values( array_unique( $validated_ids ) );
		foreach ( $validated_ids as $property_id ) {
			if ( 'smartsused_property' !== get_post_type( $property_id ) || 'publish' !== get_post_status( $property_id ) ) {
				return new WP_Error(
					'smartsused_admin_house_invalid',
					__( 'Jeden z vybraných domov neexistuje alebo nie je publikovaný.', 'smartsused-core' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( empty( $validated_ids ) ) {
			delete_user_meta( $manager_id, SmartSused_Manager_Scope::META_KEY );
		} else {
			update_user_meta( $manager_id, SmartSused_Manager_Scope::META_KEY, $validated_ids );
		}

		return new WP_REST_Response(
			array(
				'manager_id'  => $manager_id,
				'property_ids' => $validated_ids,
			),
			200
		);
	}

	/**
	 * Convert a house post to the public API shape.
	 *
	 * @param WP_Post $post House post.
	 * @return array
	 */
	private function format_house( $post ) {
		return array(
			'id'             => (int) $post->ID,
			'title'          => get_the_title( $post ),
			'address'        => (string) $post->post_content,
			'house_number'   => (string) get_post_meta( $post->ID, '_smartsused_house_number', true ),
			'entrance_count' => (int) get_post_meta( $post->ID, '_smartsused_entrance_count', true ),
			'unit_count'     => (int) get_post_meta( $post->ID, '_smartsused_unit_count', true ),
		);
	}
}
