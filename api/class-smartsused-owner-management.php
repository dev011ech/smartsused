<?php
/**
 * Manages SmartSused owners and their unit assignments.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers manager-only owner management endpoints.
 */
final class SmartSused_Owner_Management {

	/**
	 * Register owner management routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register list, available-unit, and create-owner endpoints.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/owners',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_owners' ),
				'permission_callback' => array( $this, 'permission_to_manage_owners' ),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/owners/units',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_available_units' ),
				'permission_callback' => array( $this, 'permission_to_manage_owners' ),
				'args'                => array(
					'property_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/owners/create',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_owner' ),
				'permission_callback' => array( $this, 'permission_to_manage_owners' ),
				'args'                => array(
					'first_name' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'last_name'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'phone'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'unit_id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/owners/(?P<owner_id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_owner' ),
				'permission_callback' => array( $this, 'permission_to_manage_owners' ),
				'args'                => array(
					'owner_id'   => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
					'first_name' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'last_name'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'email'      => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
					'phone'      => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/owners/(?P<owner_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_owner_relationships' ),
				'permission_callback' => array( $this, 'permission_to_manage_owners' ),
				'args'                => array(
					'owner_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * Require a logged-in manager with a verified JWT.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permission_to_manage_owners( $request ) {
		$authorization = $request->get_header( 'authorization' );
		$user_id       = get_current_user_id();

		if (
			! is_string( $authorization )
			|| 1 !== preg_match( '/^Bearer\s+\S+$/i', $authorization )
			|| ! is_user_logged_in()
			|| $user_id < 1
		) {
			return self::forbidden_error();
		}

		$verified_user_id = apply_filters( 'smartsused_verified_jwt_user_id', 0, $request );
		$user             = get_userdata( $user_id );

		if (
			! is_int( $verified_user_id )
			|| $verified_user_id !== $user_id
			|| ! ( $user instanceof WP_User )
			|| ! in_array( 'smartsused_manager', $user->roles, true )
			|| ( SmartSused_Manager_Scope::is_scoped_manager( $user_id ) && empty( SmartSused_Manager_Scope::get_property_ids( $user_id ) ) )
		) {
			return self::forbidden_error();
		}

		return true;
	}

	/**
	 * List registered owners and their assigned units.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_owners( $request ) {
		global $wpdb;

		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$args   = array(
			'role__in' => array( 'smartsused_owner', 'smartsused_tenant' ),
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 1000,
			'fields'  => array( 'ID', 'user_email', 'display_name' ),
		);

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_email', 'display_name', 'user_login' );
		}

		$users   = get_users( $args );
		$user_ids = array_map(
			static function ( $user ) {
				return (int) $user->ID;
			},
			$users
		);
		$units_by_user = array();

		if ( ! empty( $user_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			$manager_property_ids = SmartSused_Manager_Scope::is_scoped_manager()
				? SmartSused_Manager_Scope::get_property_ids()
				: array();
			$property_clause = ! empty( $manager_property_ids )
				? ' AND u.post_parent IN (' . implode( ',', array_fill( 0, count( $manager_property_ids ), '%d' ) ) . ')'
				: '';
			$query        = $wpdb->prepare(
				"SELECT r.user_id, r.unit_id, r.resident_type, u.post_title AS unit_title, u.post_parent AS property_id,
					p.post_title AS property_title
				FROM {$wpdb->prefix}smartsused_unit_relationships AS r
				INNER JOIN {$wpdb->posts} AS u ON u.ID = r.unit_id
					AND u.post_type = 'smartsused_unit' AND u.post_status = 'publish'
				LEFT JOIN {$wpdb->posts} AS p ON p.ID = u.post_parent
					AND p.post_type = 'smartsused_property' AND p.post_status = 'publish'
				WHERE r.resident_type IN ('owner', 'tenant') AND r.user_id IN ({$placeholders}){$property_clause}
				ORDER BY p.post_title ASC, u.post_title ASC",
				array_merge( $user_ids, $manager_property_ids )
			);
			$relationships = $wpdb->get_results( $query, ARRAY_A );

			if ( ! is_array( $relationships ) ) {
				return new WP_Error(
					'smartsused_owner_list_failed',
					__( 'Zoznam priradených bytov sa nepodarilo načítať.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}

			foreach ( $relationships as $relationship ) {
				$user_id = (int) $relationship['user_id'];

				if ( ! isset( $units_by_user[ $user_id ] ) ) {
					$units_by_user[ $user_id ] = array();
				}

				$units_by_user[ $user_id ][] = array(
					'id'            => (int) $relationship['unit_id'],
					'resident_type' => $relationship['resident_type'],
					'title'         => $relationship['unit_title'],
					'property_id'   => (int) $relationship['property_id'],
					'property_name' => $relationship['property_title'] ?: __( 'Neznámy bytový dom', 'smartsused-core' ),
					'floor'         => get_post_meta( (int) $relationship['unit_id'], '_poschodie', true ),
					'entrance'      => get_post_meta( (int) $relationship['unit_id'], '_vchod', true ),
				);
			}
		}

		$owners = array();

		foreach ( $users as $user ) {
			if ( SmartSused_Manager_Scope::is_scoped_manager() && empty( $units_by_user[ $user->ID ] ) ) {
				continue;
			}

			$wordpress_user = get_userdata( (int) $user->ID );
			if ( ! ( $wordpress_user instanceof WP_User ) ) {
				return new WP_Error(
					'smartsused_owner_user_load_failed',
					__( 'Údaje obyvateľa sa nepodarilo načítať.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}

			$owners[] = array(
				'id'          => (int) $user->ID,
				'name'        => $user->display_name,
				'first_name'  => get_user_meta( $user->ID, 'first_name', true ),
				'last_name'   => get_user_meta( $user->ID, 'last_name', true ),
				'email'       => $user->user_email,
				'phone'       => get_user_meta( $user->ID, 'smartsused_phone', true ),
				'resident_type' => in_array( 'smartsused_tenant', $wordpress_user->roles, true ) ? 'tenant' : 'owner',
				'units'       => isset( $units_by_user[ $user->ID ] ) ? $units_by_user[ $user->ID ] : array(),
			);
		}

		return new WP_REST_Response( array( 'owners' => $owners ), 200 );
	}

	/**
	 * List units in a property that do not yet have a resident assignment.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_available_units( $request ) {
		global $wpdb;

		$property_id = absint( $request->get_param( 'property_id' ) );

		if (
			$property_id < 1
			|| 'smartsused_property' !== get_post_type( $property_id )
			|| 'publish' !== get_post_status( $property_id )
			|| ( SmartSused_Manager_Scope::is_scoped_manager() && ! SmartSused_Manager_Scope::is_property_assigned( $property_id ) )
		) {
			return new WP_Error(
				'smartsused_owner_invalid_property',
				__( 'Vyberte platný publikovaný bytový dom.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$units = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.post_title, floor_meta.meta_value AS floor, entrance_meta.meta_value AS entrance
				FROM {$wpdb->posts} AS u
				LEFT JOIN {$wpdb->postmeta} AS floor_meta
					ON floor_meta.post_id = u.ID AND floor_meta.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} AS entrance_meta
					ON entrance_meta.post_id = u.ID AND entrance_meta.meta_key = %s
				LEFT JOIN {$wpdb->prefix}smartsused_unit_relationships AS r ON r.unit_id = u.ID
				WHERE u.post_type = %s AND u.post_status = %s AND u.post_parent = %d
					AND r.id IS NULL
				ORDER BY u.post_title ASC",
				'_poschodie',
				'_vchod',
				'smartsused_unit',
				'publish',
				$property_id
			),
			ARRAY_A
		);

		if ( ! is_array( $units ) ) {
			return new WP_Error(
				'smartsused_owner_units_failed',
				__( 'Voľné bytové jednotky sa nepodarilo načítať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$result = array();

		foreach ( $units as $unit ) {
			$result[] = array(
				'id'       => (int) $unit['ID'],
				'title'    => $unit['post_title'],
				'floor'    => $unit['floor'],
				'entrance' => $unit['entrance'],
			);
		}

		return new WP_REST_Response( array( 'units' => $result ), 200 );
	}

	/**
	 * Create or reuse an owner account and assign it to an available unit.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_owner( $request ) {
		global $wpdb;

		$first_name = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
		$last_name  = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
		$email      = strtolower( sanitize_email( $request->get_param( 'email' ) ) );
		$phone      = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$unit_id    = absint( $request->get_param( 'unit_id' ) );

		if (
			'' === $first_name
			|| '' === $last_name
			|| ! is_email( $email )
			|| strlen( $email ) > 60
			|| $unit_id < 1
			|| ( '' !== $phone && ! SmartSused_Validation::is_valid_phone( $phone ) )
		) {
			return new WP_Error(
				'smartsused_owner_invalid_data',
				__( 'Skontrolujte meno, priezvisko, e-mail, telefón a vybraný byt.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if (
			'smartsused_unit' !== get_post_type( $unit_id )
			|| 'publish' !== get_post_status( $unit_id )
			|| 'smartsused_property' !== get_post_type( wp_get_post_parent_id( $unit_id ) )
			|| 'publish' !== get_post_status( wp_get_post_parent_id( $unit_id ) )
			|| ( SmartSused_Manager_Scope::is_scoped_manager() && ! SmartSused_Manager_Scope::is_property_assigned( wp_get_post_parent_id( $unit_id ) ) )
		) {
			return new WP_Error(
				'smartsused_owner_invalid_unit',
				__( 'Vybraný byt nie je dostupný.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if ( null === get_role( 'smartsused_owner' ) ) {
			return new WP_Error(
				'smartsused_owner_role_missing',
				__( 'Rola vlastníka nie je nakonfigurovaná. Znova aktivujte SmartSused Core.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$lock_name = 'smartsused_owner_unit_' . $unit_id;
		$lock      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

		if ( '1' !== (string) $lock ) {
			return new WP_Error(
				'smartsused_owner_lock_failed',
				__( 'Vlastníka sa nepodarilo bezpečne pripraviť na zápis. Skúste to znova.', 'smartsused-core' ),
				array( 'status' => 503 )
			);
		}

		$relationship_table = $wpdb->prefix . 'smartsused_unit_relationships';
		$existing           = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$relationship_table} WHERE unit_id = %d LIMIT 1",
				$unit_id
			),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			$this->release_lock( $lock_name );
			return new WP_Error(
				'smartsused_owner_unit_not_available',
				__( 'Vybraný byt už má priradeného vlastníka alebo nájomcu.', 'smartsused-core' ),
				array( 'status' => 409 )
			);
		}

		$user_id  = email_exists( $email );
		$new_user = false;

		if ( false === $user_id ) {
			if ( username_exists( $email ) ) {
				$this->release_lock( $lock_name );
				return new WP_Error(
					'smartsused_owner_username_exists',
					__( 'E-mail sa už používa ako prihlasovacie meno iného účtu.', 'smartsused-core' ),
					array( 'status' => 409 )
				);
			}

			$user_id = wp_insert_user(
				array(
					'user_login' => $email,
					'user_email' => $email,
					'user_pass'  => wp_generate_password( 32, true, true ),
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'role'       => 'smartsused_owner',
				)
			);

			if ( is_wp_error( $user_id ) || ! is_int( $user_id ) || $user_id < 1 ) {
				$this->release_lock( $lock_name );
				return new WP_Error(
					'smartsused_owner_create_user_failed',
					__( 'Účet vlastníka sa nepodarilo vytvoriť.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}

			$new_user = true;
		}

		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );

		if ( ! ( $user instanceof WP_User ) ) {
			$this->cleanup_user( $user_id, $new_user );
			$this->release_lock( $lock_name );
			return new WP_Error(
				'smartsused_owner_user_missing',
				__( 'Účet vlastníka sa nepodarilo načítať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			$this->cleanup_user( $user_id, $new_user );
			$this->release_lock( $lock_name );
			return new WP_Error(
				'smartsused_owner_transaction_failed',
				__( 'Databázovú transakciu sa nepodarilo spustiť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$inserted = $wpdb->insert(
			$relationship_table,
			array(
				'unit_id'       => $unit_id,
				'user_id'       => $user_id,
				'resident_type' => 'owner',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			$this->cleanup_user( $user_id, $new_user );
			$this->release_lock( $lock_name );
			return new WP_Error(
				'smartsused_owner_relationship_failed',
				__( 'Vlastníka sa nepodarilo priradiť k bytu.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( '' !== $phone && ! update_user_meta( $user_id, 'smartsused_phone', $phone ) ) {
			if ( $phone !== get_user_meta( $user_id, 'smartsused_phone', true ) ) {
				$wpdb->query( 'ROLLBACK' );
				$this->cleanup_user( $user_id, $new_user );
				$this->release_lock( $lock_name );
				return new WP_Error(
					'smartsused_owner_phone_save_failed',
					__( 'Telefónne číslo sa nepodarilo uložiť; priradenie vlastníka bolo zrušené.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$this->cleanup_user( $user_id, $new_user );
			$this->release_lock( $lock_name );
			return new WP_Error(
				'smartsused_owner_relationship_failed',
				__( 'Vlastníka sa nepodarilo priradiť k bytu.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( ! in_array( 'smartsused_owner', $user->roles, true ) ) {
			$user->add_role( 'smartsused_owner' );
		}

		if ( ! smartsused_send_welcome_email( $user_id, 'smartsused_owner' ) ) {
			error_log( 'SmartSused Core: Welcome email could not be sent to a manually added owner.' );
			$this->release_lock( $lock_name );
			return new WP_Error(
				'smartsused_owner_welcome_email_failed',
				__( 'Vlastník bol vytvorený a priradený k bytu, ale personalizovaný uvítací e-mail s odkazom na aktiváciu sa nepodarilo odoslať. Skontrolujte nastavenie e-mailov na serveri.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$this->release_lock( $lock_name );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Vlastník bol pridaný. Uvítací e-mail s odkazom na nastavenie hesla bol odoslaný.', 'smartsused-core' ),
				'owner'   => array(
					'id'    => $user_id,
					'name'  => $user->display_name,
					'email' => $user->user_email,
				),
			),
			201
		);
	}

	/**
	 * Update an owner's personal details within the manager's data scope.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_owner( $request ) {
		$owner_id   = absint( $request->get_param( 'owner_id' ) );
		$first_name = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
		$last_name  = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
		$email      = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
		$phone      = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$owner      = get_userdata( $owner_id );

		if (
			$owner_id < 1
			|| ! ( $owner instanceof WP_User )
			|| ! in_array( 'smartsused_owner', $owner->roles, true )
			|| ! $this->owner_has_relationship_in_scope( $owner_id )
		) {
			return new WP_Error(
				'smartsused_owner_not_found',
				__( 'Vlastníka sa nepodarilo nájsť v spravovanom dome.', 'smartsused-core' ),
				array( 'status' => 404 )
			);
		}

		if (
			'' === $first_name
			|| '' === $last_name
			|| ! is_email( $email )
			|| strlen( $email ) > 100
			|| ( '' !== $phone && ! SmartSused_Validation::is_valid_phone( $phone ) )
		) {
			return new WP_Error(
				'smartsused_owner_invalid_data',
				__( 'Skontrolujte meno, priezvisko, e-mail a telefón.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$existing_email_user_id = email_exists( $email );
		if ( false !== $existing_email_user_id && (int) $existing_email_user_id !== $owner_id ) {
			return new WP_Error(
				'smartsused_owner_email_exists',
				__( 'Tento e-mail už používa iný účet.', 'smartsused-core' ),
				array( 'status' => 409 )
			);
		}

		$updated = wp_update_user(
			array(
				'ID'           => $owner_id,
				'user_email'   => $email,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
			)
		);

		if ( is_wp_error( $updated ) || (int) $updated !== $owner_id ) {
			return new WP_Error(
				'smartsused_owner_update_failed',
				__( 'Osobné údaje vlastníka sa nepodarilo uložiť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( ! update_user_meta( $owner_id, 'smartsused_phone', $phone ) && $phone !== get_user_meta( $owner_id, 'smartsused_phone', true ) ) {
			return new WP_Error(
				'smartsused_owner_phone_update_failed',
				__( 'Osobné údaje boli uložené, ale telefónne číslo sa nepodarilo aktualizovať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Osobné údaje vlastníka boli aktualizované.', 'smartsused-core' ),
			),
			200
		);
	}

	/**
	 * Remove an owner's relationships in the current manager scope, not the account.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_owner_relationships( $request ) {
		global $wpdb;

		$owner_id = absint( $request->get_param( 'owner_id' ) );
		$owner    = get_userdata( $owner_id );
		if (
			$owner_id < 1
			|| ! ( $owner instanceof WP_User )
			|| ! in_array( 'smartsused_owner', $owner->roles, true )
			|| ! $this->owner_has_relationship_in_scope( $owner_id )
		) {
			return new WP_Error(
				'smartsused_owner_not_found',
				__( 'Vlastníka sa nepodarilo nájsť v spravovanom dome.', 'smartsused-core' ),
				array( 'status' => 404 )
			);
		}

		$table              = $wpdb->prefix . 'smartsused_unit_relationships';
		$property_ids       = SmartSused_Manager_Scope::is_scoped_manager() ? SmartSused_Manager_Scope::get_property_ids() : array();
		$property_condition = ! empty( $property_ids )
			? $wpdb->prepare(
				" AND unit_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_parent IN (" . implode( ',', array_fill( 0, count( $property_ids ), '%d' ) ) . '))',
				array_merge( array( 'smartsused_unit', 'publish' ), $property_ids )
			)
			: '';
		$deleted            = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND resident_type = %s{$property_condition}",
				$owner_id,
				'owner'
			)
		);

		if ( false === $deleted ) {
			return new WP_Error(
				'smartsused_owner_relationship_delete_failed',
				__( 'Priradenie vlastníka k bytom sa nepodarilo odstrániť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of removed relationships. */
					_n( 'Vlastník bol odobratý z %d bytu.', 'Vlastník bol odobratý z %d bytov.', $deleted, 'smartsused-core' ),
					$deleted
				),
				'removed' => (int) $deleted,
			),
			200
		);
	}

	/**
	 * Check for an owner relationship in the current manager's property scope.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return bool
	 */
	private function owner_has_relationship_in_scope( $owner_id ) {
		global $wpdb;

		$property_ids       = SmartSused_Manager_Scope::is_scoped_manager() ? SmartSused_Manager_Scope::get_property_ids() : array();
		$property_condition = ! empty( $property_ids )
			? ' AND unit.post_parent IN (' . implode( ',', array_fill( 0, count( $property_ids ), '%d' ) ) . ')'
			: '';
		$parameters         = array( $owner_id, 'owner' );

		if ( ! empty( $property_ids ) ) {
			$parameters = array_merge( $parameters, $property_ids );
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT relationship.id
				FROM {$wpdb->prefix}smartsused_unit_relationships AS relationship
				INNER JOIN {$wpdb->posts} AS unit ON unit.ID = relationship.unit_id
					AND unit.post_type = 'smartsused_unit' AND unit.post_status = 'publish'
				WHERE relationship.user_id = %d AND relationship.resident_type = %s{$property_condition}
				LIMIT 1",
				$parameters
			)
		);
	}

	/**
	 * Release an email lock used to serialize account creation.
	 *
	 * @param string $lock_name Lock name.
	 */
	private function release_lock( $lock_name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}

	/**
	 * Remove a newly created account if its relation could not be persisted.
	 *
	 * @param int  $user_id  User ID.
	 * @param bool $new_user Whether this request created the user.
	 */
	private function cleanup_user( $user_id, $new_user ) {
		if ( ! $new_user ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		if ( ! wp_delete_user( $user_id ) ) {
			error_log( 'SmartSused Core: A new owner account could not be removed after assignment failure.' );
		}
	}

	/**
	 * Return the standard owner management permission error.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'smartsused_owner_management_forbidden',
			__( 'Na správu vlastníkov nemáte oprávnenie.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}
}
