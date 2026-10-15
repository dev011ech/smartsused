<?php
/**
 * Handles owner-issued tenant invitations and tenant registration.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issue single-use, expiring invitations for tenants.
 */
final class SmartSused_Tenant_Invites {

	/**
	 * Current invitations-table schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * Register migration and REST hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_upgrade_schema' ), 1 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Create the invitations table during plugin activation.
	 */
	public static function activate() {
		if ( ! self::ensure_schema() ) {
			wp_die( esc_html__( 'Tabuľku pozvánok SmartSused sa nepodarilo vytvoriť.', 'smartsused-core' ) );
		}
	}

	/**
	 * Upgrade the invitations table for existing plugin installations.
	 */
	public function maybe_upgrade_schema() {
		if (
			self::SCHEMA_VERSION !== get_option( 'smartsused_invites_db_version' )
			|| ! self::table_exists()
		) {
			self::ensure_schema();
		}
	}

	/**
	 * Register invitation and registration endpoints.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/tenant/units',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_owner_units' ),
				'permission_callback' => array( $this, 'permission_to_list_resident_units' ),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/tenant/invite',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'invite_tenant' ),
				'permission_callback' => array( $this, 'permission_to_invite' ),
				'args'                => array(
					'email' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'unit_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/tenant/invite/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_invite' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/tenant/(?P<user_id>\d+)/unit/(?P<unit_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_tenant' ),
				'permission_callback' => array( $this, 'permission_to_manage_unit' ),
			)
		);

		register_rest_route(
			'smartsused/v1',
			'/tenant/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'register_tenant' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required' => true,
						'type'     => 'string',
					),
					'password' => array(
						'required' => false,
						'type'     => 'string',
					),
					'id_token' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Require a verified owner relationship to the requested unit.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permission_to_manage_unit( $request ) {
		$authorization = $request->get_header( 'authorization' );
		$user_id       = get_current_user_id();
		$user          = get_userdata( $user_id );
		$unit_id       = absint( $request->get_param( 'unit_id' ) );

		if (
			! is_string( $authorization )
			|| 1 !== preg_match( '/^Bearer\s+\S+$/i', $authorization )
			|| ! is_user_logged_in()
			|| $user_id < 1
			|| ! ( $user instanceof WP_User )
			|| ! in_array( 'smartsused_owner', $user->roles, true )
		) {
			return self::forbidden_error();
		}

		$verified_user_id = apply_filters( 'smartsused_verified_jwt_user_id', 0, $request );

		if ( ! is_int( $verified_user_id ) || $verified_user_id !== $user_id ) {
			return self::forbidden_error();
		}

		if ( 0 === $unit_id && '/smartsused/v1/tenant/units' === $request->get_route() ) {
			return true;
		}

		if (
			$unit_id < 1
			|| 'smartsused_unit' !== get_post_type( $unit_id )
			|| 'publish' !== get_post_status( $unit_id )
		) {
			return self::forbidden_error();
		}

		global $wpdb;
		$relationship_table = $wpdb->prefix . 'smartsused_unit_relationships';
		$is_owner           = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$relationship_table} "
					. "WHERE unit_id = %d AND user_id = %d AND resident_type = 'owner' LIMIT 1",
				$unit_id,
				$user_id
			)
		);

		return null !== $is_owner ? true : self::forbidden_error();
	}

	/**
	 * Allow owners and tenants to list only their own published units.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permission_to_list_resident_units( $request ) {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if (
			! ( $user instanceof WP_User )
			|| ! in_array( 'smartsused_tenant', $user->roles, true )
		) {
			return $this->permission_to_manage_unit( $request );
		}

		$authorization    = $request->get_header( 'authorization' );
		$verified_user_id = apply_filters( 'smartsused_verified_jwt_user_id', 0, $request );

		if (
			! is_string( $authorization )
			|| 1 !== preg_match( '/^Bearer\s+\S+$/i', $authorization )
			|| ! is_user_logged_in()
			|| ! is_int( $verified_user_id )
			|| $verified_user_id !== $user_id
		) {
			return self::forbidden_error();
		}

		$unit_id = absint( $request->get_param( 'unit_id' ) );

		if ( 0 === $unit_id ) {
			return true;
		}

		if (
			'smartsused_unit' !== get_post_type( $unit_id )
			|| 'publish' !== get_post_status( $unit_id )
		) {
			return self::forbidden_error();
		}

		global $wpdb;
		$relationship_table = $wpdb->prefix . 'smartsused_unit_relationships';
		$is_tenant          = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$relationship_table} "
					. "WHERE unit_id = %d AND user_id = %d AND resident_type = 'tenant' LIMIT 1",
				$unit_id,
				$user_id
			)
		);

		return null !== $is_tenant ? true : self::forbidden_error();
	}

	/**
	 * Require an authenticated owner who owns the requested unit.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permission_to_invite( $request ) {
		return $this->permission_to_manage_unit( $request );
	}

	/**
	 * List units owned by the current owner and resident tenants.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_owner_units( $request ) {
		global $wpdb;

		$unit_id = absint( $request->get_param( 'unit_id' ) );
		$user_id = get_current_user_id();
		$table   = $wpdb->prefix . 'smartsused_unit_relationships';
		$user    = get_userdata( $user_id );
		$is_tenant = $user instanceof WP_User && in_array( 'smartsused_tenant', $user->roles, true );

		if ( $is_tenant ) {
			$sql  = "SELECT unit_id FROM {$table} WHERE user_id = %d AND resident_type = 'tenant'";
			$args = array( $user_id );

			if ( $unit_id > 0 ) {
				$sql   .= ' AND unit_id = %d';
				$args[] = $unit_id;
			}

			$sql       .= ' ORDER BY unit_id ASC';
			$unit_ids   = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		} elseif ( $unit_id > 0 ) {
			$unit_ids = array( $unit_id );
		} else {
			$unit_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT unit_id FROM {$table} WHERE user_id = %d AND resident_type = 'owner' ORDER BY unit_id ASC",
					$user_id
				)
			);
		}

		if ( ! is_array( $unit_ids ) ) {
			return self::storage_unavailable_error();
		}

		$units = array();

		foreach ( $unit_ids as $owned_unit_id ) {
			$owned_unit_id = absint( $owned_unit_id );

			if (
				$owned_unit_id < 1
				|| 'smartsused_unit' !== get_post_type( $owned_unit_id )
				|| 'publish' !== get_post_status( $owned_unit_id )
			) {
				continue;
			}

			$tenants = $is_tenant
				? array()
				: $wpdb->get_results(
					$wpdb->prepare(
						"SELECT u.ID AS id, u.display_name AS name, u.user_email AS email "
							. "FROM {$table} r INNER JOIN {$wpdb->users} u ON u.ID = r.user_id "
							. "WHERE r.unit_id = %d AND r.resident_type = 'tenant' ORDER BY u.display_name ASC",
						$owned_unit_id
					),
					ARRAY_A
				);

			if ( ! is_array( $tenants ) ) {
				return self::storage_unavailable_error();
			}

			$units[] = array(
				'id'      => $owned_unit_id,
				'title'   => get_the_title( $owned_unit_id ),
				'tenants' => array_map(
					static function ( $tenant ) {
						return array(
							'id'    => (int) $tenant['id'],
							'name'  => sanitize_text_field( $tenant['name'] ),
							'email' => sanitize_email( $tenant['email'] ),
						);
					},
					$tenants
				),
			);
		}

		return new WP_REST_Response( array( 'units' => $units ), 200 );
	}

	/**
	 * Remove the resident-to-unit relation without deleting the user account.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_tenant( $request ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'smartsused_unit_relationships';
		$deleted  = $wpdb->delete(
			$table,
			array(
				'unit_id'       => absint( $request->get_param( 'unit_id' ) ),
				'user_id'       => absint( $request->get_param( 'user_id' ) ),
				'resident_type' => 'tenant',
			),
			array( '%d', '%d', '%s' )
		);

		if ( false === $deleted ) {
			return self::storage_unavailable_error();
		}

		if ( 0 === $deleted ) {
			return new WP_Error(
				'smartsused_tenant_relation_missing',
				__( 'Nájomca už nie je priradený k tejto bytovej jednotke.', 'smartsused-core' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Nájomca bol odobratý z bytovej jednotky.', 'smartsused-core' ),
			),
			200
		);
	}

	/**
	 * Return the email associated with a valid invitation without consuming it.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_invite( $request ) {
		global $wpdb;

		$token = $request->get_param( 'token' );

		if ( ! is_string( $token ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $token ) ) {
			return self::invalid_invite_error();
		}

		$table = self::get_table_name();
		$email = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT email FROM {$table} WHERE token_hash = %s AND consumed_at IS NULL AND expires_at > %s LIMIT 1",
				hash( 'sha256', $token ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( ! is_string( $email ) || ! is_email( $email ) ) {
			return self::invalid_invite_error();
		}

		return new WP_REST_Response( array( 'email' => sanitize_email( $email ) ), 200 );
	}

	/**
	 * Create a one-use invitation and email its registration link.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function invite_tenant( $request ) {
		global $wpdb;

		$email   = strtolower( trim( sanitize_email( $request->get_param( 'email' ) ) ) );
		$unit_id = absint( $request->get_param( 'unit_id' ) );
		$user_id = get_current_user_id();

		if ( ! is_email( $email ) || $unit_id < 1 ) {
			return new WP_Error(
				'smartsused_tenant_invite_invalid_input',
				__( 'Zadajte platný e-mail a bytovú jednotku.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if ( email_exists( $email ) ) {
			return new WP_Error(
				'smartsused_tenant_invite_account_exists',
				__( 'Pre tento e-mail už účet existuje. Nájomca sa môže prihlásiť alebo obnoviť heslo.', 'smartsused-core' ),
				array( 'status' => 409 )
			);
		}

		if ( ! self::table_exists() ) {
			return self::storage_unavailable_error();
		}

		$table = self::get_table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->last_error = '';
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE invited_by = %d AND created_at >= %s",
				$user_id,
				$since
			)
		);

		if ( '' !== $wpdb->last_error ) {
			return self::storage_unavailable_error();
		}

		if ( null === $count ) {
			return self::storage_unavailable_error();
		}

		if ( (int) $count >= 5 ) {
			return new WP_Error(
				'smartsused_tenant_invite_rate_limited',
				__( 'Za poslednú hodinu ste odoslali príliš veľa pozvánok. Skúste to neskôr.', 'smartsused-core' ),
				array( 'status' => 429 )
			);
		}

		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error(
				'smartsused_tenant_invite_token_failed',
				__( 'Bezpečný pozývací token sa nepodarilo vytvoriť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$token_hash = hash( 'sha256', $token );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$cleanup    = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at <= %s",
				$now
			)
		);

		if ( false === $cleanup ) {
			return self::storage_unavailable_error();
		}

		$expires    = gmdate( 'Y-m-d H:i:s', time() + ( 48 * HOUR_IN_SECONDS ) );
		$inserted   = $wpdb->insert(
			$table,
			array(
				'token_hash' => $token_hash,
				'email'      => $email,
				'unit_id'    => $unit_id,
				'invited_by' => $user_id,
				'created_at' => $now,
				'expires_at' => $expires,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'smartsused_tenant_invite_save_failed',
				__( 'Pozvánku sa nepodarilo bezpečne uložiť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$registration_url = 'https://smartsused.sk/register-tenant?token=' . rawurlencode( $token );
		$subject          = __( 'Pozvánka do aplikácie SmartSused', 'smartsused-core' );
		$message          = __( 'Vlastník bytu vás pozýva do aplikácie SmartSused. Kliknutím na tento odkaz dokončíte svoju registráciu: https://smartsused.sk', 'smartsused-core' )
			. "\n\n"
			. $registration_url;
		$sent             = wp_mail(
			$email,
			$subject,
			$message,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		if ( ! $sent ) {
			return new WP_Error(
				'smartsused_tenant_invite_email_failed',
				__( 'E-mail s pozvánkou sa nepodarilo odoslať. Skontrolujte nastavenie odosielania e-mailov.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Pozvánka bola odoslaná na zadaný e-mail.', 'smartsused-core' ),
			),
			201
		);
	}

	/**
	 * Register a tenant using a valid invitation token and a new password.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_tenant( $request ) {
		global $wpdb;

		$token    = $request->get_param( 'token' );
		$password = $request->get_param( 'password' );
		$id_token = $request->get_param( 'id_token' );

		if (
			! is_string( $token )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $token )
			|| ( ! is_string( $password ) && ! is_string( $id_token ) )
			|| ( is_string( $password ) && '' !== $password && ( strlen( $password ) < 12 || strlen( $password ) > 4096 ) )
			|| ( is_string( $id_token ) && strlen( $id_token ) > 8192 )
			|| ( is_string( $password ) && '' !== $password && is_string( $id_token ) && '' !== $id_token )
		) {
			return new WP_Error(
				'smartsused_tenant_register_invalid_input',
				__( 'Pozývací odkaz alebo heslo nie je platné. Heslo musí mať aspoň 12 znakov.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if ( null === get_role( 'smartsused_tenant' ) ) {
			return new WP_Error(
				'smartsused_tenant_role_missing',
				__( 'Rola nájomcu nie je nakonfigurovaná. Skontrolujte aktiváciu SmartSused Core.', 'smartsused-core' ),
				array( 'status' => 503 )
			);
		}

		if ( ! self::tables_support_transactions() ) {
			return self::storage_unavailable_error();
		}

		$invites_table      = self::get_table_name();
		$relationships_table = $wpdb->prefix . 'smartsused_unit_relationships';
		$token_hash         = hash( 'sha256', $token );
		$now                = gmdate( 'Y-m-d H:i:s' );
		$google_identity    = null;

		if ( is_string( $id_token ) && '' !== $id_token ) {
			$google_identity = SmartSused_REST_API::verify_google_identity( $id_token );

			if ( is_wp_error( $google_identity ) ) {
				return $google_identity;
			}
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::storage_unavailable_error();
		}

		$wpdb->last_error = '';
		$invite           = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, email, unit_id FROM {$invites_table} "
					. 'WHERE token_hash = %s AND consumed_at IS NULL AND expires_at > %s LIMIT 1 FOR UPDATE',
				$token_hash,
				$now
			),
			ARRAY_A
		);

		if ( '' !== $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return self::storage_unavailable_error();
		}

		if (
			! is_array( $invite )
			|| 'smartsused_unit' !== get_post_type( (int) $invite['unit_id'] )
			|| 'publish' !== get_post_status( (int) $invite['unit_id'] )
		) {
			$wpdb->query( 'ROLLBACK' );
			return self::invalid_invite_error();
		}

		$email_lock_name = 'smartsused_tenant_' . substr( hash( 'sha256', strtolower( $invite['email'] ) ), 0, 32 );
		$email_lock      = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $email_lock_name, 5 )
		);

		if ( 1 !== (int) $email_lock ) {
			$wpdb->query( 'ROLLBACK' );
			return self::storage_unavailable_error();
		}

		try {
			if ( is_array( $google_identity ) ) {
				if ( strtolower( sanitize_email( $google_identity['email'] ) ) !== strtolower( $invite['email'] ) ) {
					$wpdb->query( 'ROLLBACK' );
					return self::invalid_invite_error();
				}

				$password = wp_generate_password( 40, true, true );
			} elseif ( ! is_string( $password ) || strlen( $password ) < 12 || strlen( $password ) > 4096 ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'smartsused_tenant_register_invalid_input',
					__( 'Pozývací odkaz alebo heslo nie je platné. Heslo musí mať aspoň 12 znakov.', 'smartsused-core' ),
					array( 'status' => 400 )
				);
			}

			if ( email_exists( $invite['email'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::invalid_invite_error();
			}

			$local_part = strstr( $invite['email'], '@', true );
			$user_login = (string) $local_part;

			if ( '' === $user_login ) {
				$user_login = 'tenant';
			}

			$user_login = sanitize_user(
				$user_login . '-' . strtolower( wp_generate_password( 8, false, false ) ),
				true
			);
			$user_id = wp_insert_user(
				array(
					'user_login'   => $user_login,
					'user_email'   => $invite['email'],
					'user_pass'    => $password,
					'display_name' => $local_part,
					'role'         => 'smartsused_tenant',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::invalid_invite_error();
			}

			$relationship_inserted = $wpdb->insert(
				$relationships_table,
				array(
					'unit_id'       => (int) $invite['unit_id'],
					'user_id'       => (int) $user_id,
					'resident_type' => 'tenant',
					'created_at'    => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s', '%s' )
			);

			if ( false === $relationship_inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'smartsused_tenant_relationship_failed',
					__( 'Účet sa nepodarilo priradiť k bytu. Pozvánka zostala nevyužitá; skúste to znova.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}

			$consumed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$invites_table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL",
					$now,
					(int) $invite['id']
				)
			);

			if ( 1 !== $consumed || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error(
					'smartsused_tenant_register_commit_failed',
					__( 'Registráciu sa nepodarilo dokončiť. Pozvánka nebola potvrdená; skúste to znova.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Registrácia nájomcu bola úspešná. Teraz sa môžete prihlásiť.', 'smartsused-core' ),
				),
				201
			);
		} finally {
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $email_lock_name )
			);
		}
	}

	/**
	 * Create or upgrade the invitation table.
	 *
	 * @return bool
	 */
	private static function ensure_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			email varchar(100) NOT NULL,
			unit_id bigint(20) unsigned NOT NULL,
			invited_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			consumed_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY email_unit (email,unit_id),
			KEY invited_by_created (invited_by,created_at),
			KEY expires_at (expires_at)
		) {$charset_collate} ENGINE=InnoDB;";

		dbDelta( $sql );

		if ( ! self::table_exists() ) {
			return false;
		}

		update_option( 'smartsused_invites_db_version', self::SCHEMA_VERSION, false );
		return true;
	}

	/**
	 * Check whether the invitations table exists.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;

		$table = self::get_table_name();
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return $table === $found;
	}

	/**
	 * Ensure all tables participating in registration support transactions.
	 *
	 * @return bool
	 */
	private static function tables_support_transactions() {
		global $wpdb;

		foreach (
			array(
				self::get_table_name(),
				$wpdb->prefix . 'smartsused_unit_relationships',
				$wpdb->users,
				$wpdb->usermeta,
			) as $table
		) {
			$status = $wpdb->get_row(
				$wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ),
				ARRAY_A
			);

			if ( ! is_array( $status ) || ! isset( $status['Engine'] ) || 'innodb' !== strtolower( $status['Engine'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return the prefixed invitations table name.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'smartsused_invites';
	}

	/**
	 * Return a generic invalid-invitation response.
	 *
	 * @return WP_Error
	 */
	private static function invalid_invite_error() {
		return new WP_Error(
			'smartsused_tenant_invite_invalid',
			__( 'Pozvánka je neplatná, už bola použitá alebo jej platnosť vypršala.', 'smartsused-core' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Return a generic access-denied response.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'smartsused_tenant_invite_forbidden',
			__( 'Na pozvanie nájomcu musíte byť vlastníkom vybranej bytovej jednotky.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Return an explicit storage error when safe registration cannot proceed.
	 *
	 * @return WP_Error
	 */
	private static function storage_unavailable_error() {
		return new WP_Error(
			'smartsused_tenant_invite_storage_unavailable',
			__( 'Úložisko pozvánok momentálne nie je dostupné alebo nepodporuje bezpečný zápis.', 'smartsused-core' ),
			array( 'status' => 503 )
		);
	}
}
