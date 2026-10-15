<?php
/**
 * Stores private files and enforces unit-based download permissions.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages secure uploads and private file downloads.
 */
final class SmartSused_Secure_Files {

	/**
	 * Register REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Check that the directory still has the expected access-denial rules.
	 *
	 * @return bool
	 */
	private static function is_storage_protected() {
		$directory     = self::get_storage_directory();
		$htaccess_path = trailingslashit( $directory ) . '.htaccess';

		return is_dir( $directory )
			&& ! is_link( $directory )
			&& is_file( $htaccess_path )
			&& ! is_link( $htaccess_path )
			&& is_readable( $htaccess_path )
			&& self::get_htaccess_content() === file_get_contents( $htaccess_path );
	}

	/**
	 * Return Apache 2.4 and legacy access-control directives.
	 *
	 * @return string
	 */
	private static function get_htaccess_content() {
		return "<IfModule mod_authz_core.c>\n"
			. "Require all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "Deny from all\n"
			. "</IfModule>\n";
	}

	/**
	 * Create the protected storage directory and plugin tables.
	 */
	public static function activate() {
		global $wpdb;

		$directory = self::get_storage_directory();

		if ( is_link( $directory ) || ( file_exists( $directory ) && ! is_dir( $directory ) ) ) {
			wp_die(
				esc_html__( 'Chránený priečinok na súbory nie je bezpečný.', 'smartsused-core' )
			);
		}

		if ( ! wp_mkdir_p( $directory ) || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			wp_die(
				esc_html__( 'Chránený priečinok na súbory sa nepodarilo vytvoriť.', 'smartsused-core' )
			);
		}

		$htaccess_path = trailingslashit( $directory ) . '.htaccess';

		if ( is_link( $htaccess_path ) ) {
			wp_die(
				esc_html__( 'Konfigurácia ochrany súborov nie je bezpečná.', 'smartsused-core' )
			);
		}

		$htaccess = self::get_htaccess_content();

		if ( strlen( $htaccess ) !== file_put_contents( $htaccess_path, $htaccess, LOCK_EX ) ) {
			wp_die(
				esc_html__( 'Ochranu priečinka so súbormi sa nepodarilo nastaviť.', 'smartsused-core' )
			);
		}

		$index_path = trailingslashit( $directory ) . 'index.php';

		$index_content = "<?php\nhttp_response_code( 404 );\nexit;\n";

		if (
			is_link( $index_path )
			|| strlen( $index_content ) !== file_put_contents( $index_path, $index_content, LOCK_EX )
		) {
			wp_die(
				esc_html__( 'Ochranný súbor priečinka sa nepodarilo vytvoriť.', 'smartsused-core' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate    = $wpdb->get_charset_collate();
		$relationships_table = self::get_relationships_table();
		$files_table         = self::get_files_table();

		$relationships_sql = "CREATE TABLE {$relationships_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			unit_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			resident_type varchar(10) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unit_user (unit_id,user_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		$files_sql = "CREATE TABLE {$files_table} (
			file_name varchar(100) NOT NULL,
			unit_id bigint(20) unsigned NOT NULL,
			mime_type varchar(100) NOT NULL,
			uploaded_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (file_name),
			KEY unit_id (unit_id)
		) {$charset_collate};";

		dbDelta( $relationships_sql );
		dbDelta( $files_sql );

		foreach ( array( $relationships_table, $files_table ) as $table ) {
			$created_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( $table !== $created_table ) {
				wp_die(
					esc_html__( 'Databázové tabuľky SmartSused sa nepodarilo vytvoriť.', 'smartsused-core' )
				);
			}
		}
	}

	/**
	 * Register the private file download endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/files/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_file' ),
				'permission_callback' => array( $this, 'permission_to_download' ),
				'args'                => array(
					'file' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Assign an owner or tenant to a unit.
	 *
	 * Only a manager or WordPress administrator may change this relation.
	 *
	 * @param int    $user_id       WordPress user ID.
	 * @param int    $unit_id       SmartSused unit post ID.
	 * @param string $resident_type Relation type: owner or tenant.
	 * @return true|WP_Error
	 */
	public static function assign_resident_to_unit( $user_id, $unit_id, $resident_type ) {
		global $wpdb;

		$actor = wp_get_current_user();

		if (
			! ( $actor instanceof WP_User )
			|| (
				! user_can( $actor, 'manage_options' )
				&& ! in_array( 'smartsused_manager', $actor->roles, true )
			)
		) {
			return new WP_Error(
				'smartsused_unit_relationship_forbidden',
				__( 'Na zmenu priradenia rezidenta k bytu nemáte oprávnenie.', 'smartsused-core' ),
				array( 'status' => 403 )
			);
		}

		$user_id       = absint( $user_id );
		$unit_id       = absint( $unit_id );
		$resident_type = sanitize_key( $resident_type );
		$user          = get_userdata( $user_id );

		if (
			$user_id < 1
			|| $unit_id < 1
			|| ! in_array( $resident_type, array( 'owner', 'tenant' ), true )
			|| ! ( $user instanceof WP_User )
			|| ! in_array( 'smartsused_' . $resident_type, $user->roles, true )
			|| 'smartsused_unit' !== get_post_type( $unit_id )
			|| 'publish' !== get_post_status( $unit_id )
		) {
			return new WP_Error(
				'smartsused_invalid_unit_relationship',
				__( 'Zadaný rezident, byt alebo typ priradenia nie je platný.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if (
			SmartSused_Manager_Scope::is_scoped_manager()
			&& ! SmartSused_Manager_Scope::is_property_assigned( wp_get_post_parent_id( $unit_id ) )
		) {
			return new WP_Error(
				'smartsused_unit_relationship_forbidden',
				__( 'Správcovi možno priradiť rezidentov iba k jednotkám jeho priradených bytových domov.', 'smartsused-core' ),
				array( 'status' => 403 )
			);
		}

		$result = $wpdb->replace(
			self::get_relationships_table(),
			array(
				'unit_id'       => $unit_id,
				'user_id'       => $user_id,
				'resident_type' => $resident_type,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'smartsused_unit_relationship_save_failed',
				__( 'Priradenie rezidenta k bytu sa nepodarilo uložiť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Verify the current user and the JWT provider's verified identity.
	 *
	 * The active JWT authentication provider must attach a callback to
	 * `smartsused_verified_jwt_user_id`. The callback must validate the
	 * Authorization bearer token cryptographically and return its user ID.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permission_to_download( $request ) {
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

		if ( ! is_int( $verified_user_id ) || $verified_user_id !== $user_id ) {
			return self::forbidden_error();
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return self::forbidden_error();
		}

		if (
			user_can( $user, 'manage_options' )
			|| in_array( 'smartsused_manager', $user->roles, true )
			|| array_intersect( array( 'smartsused_owner', 'smartsused_tenant' ), $user->roles )
		) {
			return true;
		}

		return self::forbidden_error();
	}

	/**
	 * Stream a file after checking that it belongs to the current resident's unit.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_Error Never returns on success because the response streams a file.
	 */
	public function download_file( $request ) {
		global $wpdb;

		$file_name = $request->get_param( 'file' );

		if ( ! is_string( $file_name ) || ! self::is_generated_file_name( $file_name ) ) {
			return self::forbidden_error();
		}

		$file = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT file_name, unit_id, mime_type FROM ' . self::get_files_table() . ' WHERE file_name = %s LIMIT 1',
				$file_name
			),
			ARRAY_A
		);

		if ( ! is_array( $file ) ) {
			return self::forbidden_error();
		}

		$user_id = get_current_user_id();
		$user       = get_userdata( $user_id );
		$is_administrator = $user instanceof WP_User && user_can( $user, 'manage_options' );
		$is_manager       = SmartSused_Manager_Scope::is_scoped_manager( $user_id );
		$has_unit_access  = $is_manager
			? SmartSused_Manager_Scope::is_property_assigned( wp_get_post_parent_id( (int) $file['unit_id'] ), $user_id )
			: smartsused_user_has_access_to_unit( $user_id, (int) $file['unit_id'] );

		if ( ! $is_administrator && ! $has_unit_access ) {
			return self::forbidden_error();
		}

		$path = trailingslashit( self::get_storage_directory() ) . $file_name;

		if (
			! self::is_storage_protected()
			|| is_link( $path )
			|| ! is_file( $path )
			|| ! is_readable( $path )
			|| ! self::is_file_inside_storage_directory( $path )
		) {
			return self::forbidden_error();
		}

		$mime_type = self::get_allowed_mime_type( $file_name );

		if ( false === $mime_type || $mime_type !== $file['mime_type'] ) {
			return self::forbidden_error();
		}

		$file_size = filesize( $path );

		if ( false === $file_size ) {
			return self::forbidden_error();
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
		header( 'Content-Length: ' . (string) $file_size );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Content-Security-Policy: sandbox' );

		if ( false === readfile( $path ) ) {
			error_log( 'SmartSused Core: A protected file could not be streamed.' );
		}

		exit;
	}

	/**
	 * Move a validated uploaded file into private storage and associate it with a unit.
	 *
	 * @param string $file_tmp_path Temporary path of a PHP-uploaded file.
	 * @param string $filename      Original filename used only to validate the extension.
	 * @param int    $unit_id       Optional unit that owns the file; zero means manager-only.
	 * @return string|WP_Error Generated private filename or an error.
	 */
	public static function move_file_to_secure_storage( $file_tmp_path, $filename, $unit_id ) {
		global $wpdb;

		$unit_id = absint( $unit_id );
		$unit    = get_post( $unit_id );

		if (
			! is_string( $file_tmp_path )
			|| '' === $file_tmp_path
			|| ! is_uploaded_file( $file_tmp_path )
			|| ! is_readable( $file_tmp_path )
			|| ( $unit_id > 0 && ( ! ( $unit instanceof WP_Post ) || 'smartsused_unit' !== $unit->post_type ) )
		) {
			return new WP_Error(
				'smartsused_invalid_secure_upload',
				__( 'Nahraný súbor alebo bytová jednotka nie sú platné.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if (
			$unit_id > 0
			&& SmartSused_Manager_Scope::is_scoped_manager()
			&& ! SmartSused_Manager_Scope::is_property_assigned( (int) $unit->post_parent )
		) {
			return new WP_Error(
				'smartsused_secure_upload_forbidden',
				__( 'Súbor možno priradiť iba k jednotke vášho bytového domu.', 'smartsused-core' ),
				array( 'status' => 403 )
			);
		}

		$mime_type = self::get_allowed_mime_type( $filename );

		if ( false === $mime_type ) {
			return new WP_Error(
				'smartsused_unsupported_secure_file',
				__( 'Tento typ súboru nie je povolený.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$file_size = filesize( $file_tmp_path );
		$max_size  = wp_max_upload_size();

		if ( false === $file_size || ( $max_size > 0 && $file_size > $max_size ) ) {
			return new WP_Error(
				'smartsused_secure_file_too_large',
				__( 'Súbor je väčší ako povolený limit.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'finfo_open' ) ) {
			return new WP_Error(
				'smartsused_fileinfo_unavailable',
				__( 'Overenie typu súboru nie je na serveri dostupné.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$file_info = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $file_info ) {
			return new WP_Error(
				'smartsused_fileinfo_unavailable',
				__( 'Overenie typu súboru nie je na serveri dostupné.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$detected_mime = finfo_file( $file_info, $file_tmp_path );
		unset( $file_info );

		if ( ! is_string( $detected_mime ) || $mime_type !== $detected_mime ) {
			return new WP_Error(
				'smartsused_file_type_mismatch',
				__( 'Obsah súboru nezodpovedá jeho prípone.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$directory = self::get_storage_directory();

		if ( ! self::is_storage_protected() || ! is_writable( $directory ) ) {
			return new WP_Error(
				'smartsused_secure_storage_unavailable',
				__( 'Chránené úložisko súborov nie je dostupné.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $filename ), PATHINFO_EXTENSION ) );
		$file_name = '';

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			try {
				$file_name = bin2hex( random_bytes( 32 ) ) . '.' . $extension;
			} catch ( Exception $exception ) {
				return new WP_Error(
					'smartsused_secure_name_generation_failed',
					__( 'Bezpečný názov súboru sa nepodarilo vytvoriť.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}

			$destination = trailingslashit( $directory ) . $file_name;
			$exists      = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT file_name FROM ' . self::get_files_table() . ' WHERE file_name = %s LIMIT 1',
					$file_name
				)
			);

			if ( ! file_exists( $destination ) && null === $exists ) {
				break;
			}

			$file_name = '';
		}

		if ( '' === $file_name ) {
			return new WP_Error(
				'smartsused_secure_name_generation_failed',
				__( 'Jedinečný názov súboru sa nepodarilo vytvoriť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$destination = trailingslashit( $directory ) . $file_name;

		if ( ! move_uploaded_file( $file_tmp_path, $destination ) ) {
			return new WP_Error(
				'smartsused_secure_file_move_failed',
				__( 'Súbor sa nepodarilo bezpečne uložiť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$inserted = $wpdb->insert(
			self::get_files_table(),
			array(
				'file_name'   => $file_name,
				'unit_id'     => $unit_id,
				'mime_type'   => $mime_type,
				'uploaded_by' => get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			if ( ! unlink( $destination ) ) {
				error_log( 'SmartSused Core: An unindexed protected upload could not be removed.' );
			}

			return new WP_Error(
				'smartsused_secure_file_index_failed',
				__( 'Záznam o uloženom súbore sa nepodarilo vytvoriť.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		return $file_name;
	}

	/**
	 * Determine whether the generated filename has an allowed extension.
	 *
	 * @param string $filename Filename or extension-bearing path.
	 * @return string|false MIME type or false when not allowed.
	 */
	private static function get_allowed_mime_type( $filename ) {
		if ( ! is_string( $filename ) || '' === $filename ) {
			return false;
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $filename ), PATHINFO_EXTENSION ) );
		$types     = array(
			'pdf'  => 'application/pdf',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);

		return isset( $types[ $extension ] ) ? $types[ $extension ] : false;
	}

	/**
	 * Check that a generated file is directly inside the configured storage folder.
	 *
	 * @param string $path Candidate file path.
	 * @return bool
	 */
	private static function is_file_inside_storage_directory( $path ) {
		$directory = realpath( self::get_storage_directory() );
		$file      = realpath( $path );

		if ( false === $directory || false === $file ) {
			return false;
		}

		return 0 === strcasecmp(
			wp_normalize_path( dirname( $file ) ),
			wp_normalize_path( $directory )
		);
	}

	/**
	 * Check generated storage filenames.
	 *
	 * @param string $file_name Candidate filename.
	 * @return bool
	 */
	private static function is_generated_file_name( $file_name ) {
		return 1 === preg_match( '/\A[a-f0-9]{64}\.(?:pdf|jpe?g|png|webp)\z/D', $file_name );
	}

	/**
	 * Return a consistent access-denied error without disclosing file existence.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'smartsused_file_access_denied',
			__( 'Nemáte oprávnenie na zobrazenie tohto súboru.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Return the protected storage directory.
	 *
	 * @return string
	 */
	private static function get_storage_directory() {
		return trailingslashit( WP_CONTENT_DIR ) . 'smartsused_secure_uploads';
	}

	/**
	 * Return the prefixed unit-resident relationship table name.
	 *
	 * @return string
	 */
	private static function get_relationships_table() {
		global $wpdb;

		return $wpdb->prefix . 'smartsused_unit_relationships';
	}

	/**
	 * Return the prefixed secure-file index table name.
	 *
	 * @return string
	 */
	private static function get_files_table() {
		global $wpdb;

		return $wpdb->prefix . 'smartsused_secure_files';
	}
}

/**
 * Check whether a user is an owner or tenant of the given unit.
 *
 * @param int $user_id WordPress user ID.
 * @param int $unit_id SmartSused unit post ID.
 * @return bool
 */
function smartsused_user_has_access_to_unit( $user_id, $unit_id ): bool {
	global $wpdb;

	$user_id = absint( $user_id );
	$unit_id = absint( $unit_id );
	$user    = get_userdata( $user_id );

	if (
		$user_id < 1
		|| $unit_id < 1
		|| ! ( $user instanceof WP_User )
		|| 'smartsused_unit' !== get_post_type( $unit_id )
		|| 'publish' !== get_post_status( $unit_id )
	) {
		return false;
	}

	$table = $wpdb->prefix . 'smartsused_unit_relationships';
	$resident_type = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT resident_type FROM {$table} "
				. "WHERE user_id = %d AND unit_id = %d "
				. "AND resident_type IN ('owner', 'tenant') LIMIT 1",
			$user_id,
			$unit_id
		)
	);

	return is_string( $resident_type )
		&& in_array( 'smartsused_' . $resident_type, $user->roles, true );
}

/**
 * Return the parent property ID for a SmartSused unit.
 *
 * @param int $unit_id SmartSused unit post ID.
 * @return int Parent property post ID, or zero when unavailable.
 */
function smartsused_get_property_id_by_unit_id( $unit_id ): int {
	$unit_id = absint( $unit_id );
	$unit    = get_post( $unit_id );

	if (
		! ( $unit instanceof WP_Post )
		|| 'smartsused_unit' !== $unit->post_type
		|| 'publish' !== $unit->post_status
	) {
		return 0;
	}

	return absint( $unit->post_parent );
}

/**
 * Return unique published property IDs related to a user's units.
 *
 * @param int $user_id WordPress user ID.
 * @return int[]
 */
function smartsused_get_user_property_ids( $user_id ): array {
	global $wpdb;

	$user_id = absint( $user_id );

	$user = $user_id > 0 ? get_userdata( $user_id ) : null;

	if ( ! ( $user instanceof WP_User ) ) {
		return array();
	}

	$relationships_table = $wpdb->prefix . 'smartsused_unit_relationships';
	$relationships       = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT unit_id, resident_type FROM {$relationships_table} "
				. "WHERE user_id = %d AND resident_type IN ('owner', 'tenant')",
			$user_id
		),
		ARRAY_A
	);

	if ( ! is_array( $relationships ) ) {
		return array();
	}

	$unit_ids = array();

	foreach ( $relationships as $relationship ) {
		if (
			isset( $relationship['unit_id'], $relationship['resident_type'] )
			&& in_array( 'smartsused_' . $relationship['resident_type'], $user->roles, true )
		) {
			$unit_ids[] = absint( $relationship['unit_id'] );
		}
	}

	$unit_ids = array_values( array_unique( array_filter( $unit_ids ) ) );
	_prime_post_caches( $unit_ids );
	$property_ids = array();

	foreach ( $unit_ids as $unit_id ) {
		$property_id = smartsused_get_property_id_by_unit_id( $unit_id );

		if ( $property_id > 0 ) {
			$property_ids[] = $property_id;
		}
	}

	$property_ids = array_values( array_unique( $property_ids ) );
	_prime_post_caches( $property_ids );
	$published_property_ids = array();

	foreach ( $property_ids as $property_id ) {
		$property = get_post( $property_id );

		if ( $property instanceof WP_Post && 'smartsused_property' === $property->post_type && 'publish' === $property->post_status ) {
			$published_property_ids[] = $property_id;
		}
	}

	return $published_property_ids;
}

/**
 * Store an uploaded file privately and associate it with its unit.
 *
 * Pass a unit ID to allow residents of that unit to download the file. Omitting
 * the optional unit ID stores the file as manager-only.
 *
 * @param string $file_tmp_path Temporary path of a PHP-uploaded file.
 * @param string $filename      Original filename used only to validate the extension.
 * @param int    $unit_id       Optional SmartSused unit post ID.
 * @return string|WP_Error Generated private filename or an error.
 */
function smartsused_move_file_to_secure_storage( $file_tmp_path, $filename, $unit_id = 0 ) {
	return SmartSused_Secure_Files::move_file_to_secure_storage( $file_tmp_path, $filename, $unit_id );
}
