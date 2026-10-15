<?php
/**
 * Imports SmartSused units from CSV files.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and processes the manager-only unit CSV import endpoint.
 */
final class SmartSused_Unit_Import {

	/**
	 * Import safety limits.
	 */
	const MAX_FILE_SIZE = 5242880;
	const MAX_ROWS      = 5000;
	const MAX_ERRORS    = 100;

	/**
	 * Register the import endpoint.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the manager-only CSV import route.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/import/units',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_units' ),
				'permission_callback' => array( $this, 'permission_to_import' ),
			)
		);
	}

	/**
	 * Require a manager with an explicitly verified JWT.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permission_to_import( $request ) {
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
	 * Import units from a semicolon-delimited CSV file.
	 *
	 * Each row is committed independently, so a bad row does not roll back
	 * successful rows and a failed post/meta write cannot leave partial data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_units( $request ) {
		global $wpdb;

		$upload = $request->get_file_params();
		$upload = isset( $upload['file'] ) ? $upload['file'] : null;

		$data = SmartSused_Import_File::read_rows( $upload, self::MAX_FILE_SIZE, self::MAX_ROWS, 4 );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $upload ) ) {
			return new WP_Error(
				'smartsused_invalid_unit_csv',
				__( 'Nahrajte platný súbor s príponou .csv alebo .xlsx.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! post_type_exists( 'smartsused_unit' ) ) {
			return new WP_Error(
				'smartsused_unit_post_type_missing',
				__( 'Typ bytovej jednotky nie je dostupný. Skontrolujte, či je SmartSused Core správne aktivovaný.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$transaction_error = $this->require_transactional_tables();

		if ( is_wp_error( $transaction_error ) ) {
			return $transaction_error;
		}

		$header = SmartSused_Import_File::normalize_headers( $data['header'] );

		$expected_header = array( 'nazov_domu', 'cislo_bytu', 'poschodie', 'vchod' );

		if ( $expected_header !== $header ) {
			return new WP_Error(
				'smartsused_unit_csv_invalid_header',
				__( 'Súbor musí obsahovať stĺpce v poradí: Názov bytového domu;Číslo bytu;Poschodie;Vchod.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$counts = array(
			'created'           => 0,
			'duplicates_skipped' => 0,
			'failed'            => 0,
		);
		$errors         = array();
		$errors_omitted = false;
		$row_number     = 1;
		$property_cache = array();
		$duplicate_cache = array();

		foreach ( $data['rows'] as $entry ) {
			$row_number = $entry['number'];
			$row        = $entry['values'];

			if ( ! is_array( $row ) || count( $expected_header ) !== count( $row ) ) {
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Riadok musí obsahovať presne štyri stĺpce.', 'smartsused-core' )
				);
				continue;
			}

			$values = array_map(
				static function ( $value ) {
					return is_string( $value ) ? trim( $value ) : '';
				},
				$row
			);

			list( $property_name, $unit_number, $floor, $entrance ) = $values;
			$property_name = sanitize_text_field( $property_name );
			$unit_number   = sanitize_text_field( $unit_number );
			$floor         = sanitize_text_field( $floor );
			$entrance      = sanitize_text_field( $entrance );

			if ( '' === $property_name || '' === $unit_number || '' === $entrance ) {
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Názov bytového domu, číslo bytu a vchod sú povinné.', 'smartsused-core' )
				);
				continue;
			}

			if ( '' !== $floor && ( ! preg_match( '/^-?\d+$/', $floor ) || (int) $floor < -10 || (int) $floor > 250 ) ) {
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Poschodie musí byť celé číslo od -10 do 250.', 'smartsused-core' )
				);
				continue;
			}

			$property_id = $this->find_property_id( $property_name, $property_cache );

			if ( 0 === $property_id ) {
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					sprintf(
						/* translators: %s: property title from the CSV file. */
						__( 'Bytový dom s názvom %s neexistuje. Jednotku nebolo možné naimportovať.', 'smartsused-core' ),
						$property_name
					)
				);
				continue;
			}

			if (
				SmartSused_Manager_Scope::is_scoped_manager()
				&& ! SmartSused_Manager_Scope::is_property_assigned( $property_id )
			) {
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Importovať možno iba jednotky bytových domov priradených k vášmu účtu.', 'smartsused-core' )
				);
				continue;
			}

			$title     = sprintf(
				'Vchod %1$s, Byt %2$s',
				$entrance,
				$unit_number
			);
			$cache_key = $property_id . ':' . strtolower( $title );

			if ( isset( $duplicate_cache[ $cache_key ] ) ) {
				$counts['duplicates_skipped']++;
				continue;
			}

			$lock_name = 'smartsused_unit_' . md5( $cache_key );
			$lock      = $wpdb->get_var(
				$wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name )
			);

			if ( '1' !== (string) $lock ) {
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Jednotku sa nepodarilo bezpečne rezervovať na zápis.', 'smartsused-core' )
				);
				continue;
			}

			if ( $this->unit_exists( $property_id, $title ) ) {
				$this->release_unit_lock( $lock_name );
				$duplicate_cache[ $cache_key ] = true;
				$counts['duplicates_skipped']++;
				continue;
			}

			$transaction_started = $wpdb->query( 'START TRANSACTION' );

			if ( false === $transaction_started ) {
				$this->release_unit_lock( $lock_name );
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Databázovú transakciu sa nepodarilo spustiť.', 'smartsused-core' )
				);
				continue;
			}

			$unit_id = wp_insert_post(
				array(
					'post_type'   => 'smartsused_unit',
					'post_title'  => $title,
					'post_status' => 'publish',
					'post_parent' => $property_id,
				),
				true
			);

			if ( is_wp_error( $unit_id ) || ! is_int( $unit_id ) || $unit_id < 1 ) {
				$this->rollback_transaction();
				$this->release_unit_lock( $lock_name );
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Bytovú jednotku sa nepodarilo vytvoriť.', 'smartsused-core' )
				);
				continue;
			}

			$floor_saved    = add_post_meta( $unit_id, '_poschodie', $floor, true );
			$entrance_saved = add_post_meta( $unit_id, '_vchod', $entrance, true );

			if ( false === $floor_saved || false === $entrance_saved ) {
				$this->rollback_transaction();
				clean_post_cache( $unit_id );
				$this->release_unit_lock( $lock_name );
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Meta údaje bytovej jednotky sa nepodarilo uložiť; zmeny riadka boli vrátené.', 'smartsused-core' )
				);
				continue;
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$this->rollback_transaction();
				clean_post_cache( $unit_id );
				$this->release_unit_lock( $lock_name );
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Bytovú jednotku sa nepodarilo potvrdiť v databáze.', 'smartsused-core' )
				);
				continue;
			}

			$this->release_unit_lock( $lock_name );
			$duplicate_cache[ $cache_key ] = true;
			$counts['created']++;
		}

		return new WP_REST_Response(
			array(
				'success'           => true,
				'message'           => sprintf(
					/* translators: 1: created unit count, 2: duplicate count, 3: failed row count. */
					__( 'Import bol dokončený. Vytvorené jednotky: %1$d, preskočené duplicity: %2$d, chybné riadky: %3$d.', 'smartsused-core' ),
					$counts['created'],
					$counts['duplicates_skipped'],
					$counts['failed']
				),
				'created'           => $counts['created'],
				'duplicates_skipped' => $counts['duplicates_skipped'],
				'failed'            => $counts['failed'],
				'errors'            => $errors,
				'errors_omitted'    => $errors_omitted,
			),
			200
		);
	}

	/**
	 * Look up a published property once per unique title.
	 *
	 * @param string $property_name Property title from CSV.
	 * @param array  $cache         Title-to-ID cache.
	 * @return int
	 */
	private function find_property_id( $property_name, &$cache ) {
		if ( array_key_exists( $property_name, $cache ) ) {
			return $cache[ $property_name ];
		}

		$property = get_page_by_title( $property_name, OBJECT, 'smartsused_property' );

		if (
			! ( $property instanceof WP_Post )
			|| 'publish' !== $property->post_status
		) {
			$cache[ $property_name ] = 0;
			return 0;
		}

		$cache[ $property_name ] = (int) $property->ID;
		return $cache[ $property_name ];
	}

	/**
	 * Check for an existing unit with the same parent and title.
	 *
	 * @param int    $property_id Parent property ID.
	 * @param string $title       Unit title.
	 * @return bool
	 */
	private function unit_exists( $property_id, $title ) {
		$existing = get_posts(
			array(
				'post_type'              => 'smartsused_unit',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'post_parent'            => $property_id,
				'title'                  => $title,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $existing );
	}

	/**
	 * Release the advisory lock used to prevent concurrent duplicate imports.
	 *
	 * @param string $lock_name MySQL advisory lock name.
	 */
	private function release_unit_lock( $lock_name ) {
		global $wpdb;

		$released = $wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
		);

		if ( '1' !== (string) $released ) {
			error_log( '[SmartSused Core] Nepodarilo sa uvoľniť zámok importu bytovej jednotky.' );
		}
	}

	/**
	 * Roll back the current row transaction and log any database failure.
	 */
	private function rollback_transaction() {
		global $wpdb;

		if ( false === $wpdb->query( 'ROLLBACK' ) ) {
			error_log( '[SmartSused Core] Nepodarilo sa vrátiť transakciu importu bytovej jednotky.' );
		}
	}

	/**
	 * Ensure posts and postmeta are stored in transactional tables.
	 *
	 * @return true|WP_Error
	 */
	private function require_transactional_tables() {
		global $wpdb;

		foreach ( array( $wpdb->posts, $wpdb->postmeta ) as $table ) {
			$status = $wpdb->get_row(
				$wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table )
			);

			if (
				! is_object( $status )
				|| ! isset( $status->Engine )
				|| 0 !== strcasecmp( 'InnoDB', $status->Engine )
			) {
				return new WP_Error(
					'smartsused_unit_import_transactions_unavailable',
					__( 'Databázové tabuľky WordPressu nepodporujú bezpečné transakcie. Import jednotiek bol zastavený.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}
		}

		return true;
	}

	/**
	 * Validate uploaded CSV metadata and content.
	 *
	 * @param array $upload Uploaded file entry.
	 * @return bool
	 */
	private function is_valid_upload( $upload ) {
		if (
			! isset( $upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'] )
			|| UPLOAD_ERR_OK !== (int) $upload['error']
			|| ! is_string( $upload['tmp_name'] )
			|| ! is_uploaded_file( $upload['tmp_name'] )
			|| ! is_string( $upload['name'] )
			|| 'csv' !== strtolower( pathinfo( sanitize_file_name( $upload['name'] ), PATHINFO_EXTENSION ) )
			|| ! is_numeric( $upload['size'] )
			|| (int) $upload['size'] < 1
			|| (int) $upload['size'] > self::MAX_FILE_SIZE
			|| ! is_readable( $upload['tmp_name'] )
			|| (int) filesize( $upload['tmp_name'] ) !== (int) $upload['size']
			|| ! function_exists( 'finfo_open' )
		) {
			return false;
		}

		$file_info = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $file_info ) {
			return false;
		}

		$mime_type = finfo_file( $file_info, $upload['tmp_name'] );
		finfo_close( $file_info );

		return is_string( $mime_type )
			&& in_array(
				$mime_type,
				array( 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel' ),
				true
			);
	}

	/**
	 * Record one failed row while keeping the response bounded.
	 *
	 * @param array  $errors         Collected errors.
	 * @param bool   $errors_omitted Whether details were truncated.
	 * @param array  $counts         Import counters.
	 * @param int    $row_number     CSV row number.
	 * @param string $message        Localized message.
	 */
	private function record_error( &$errors, &$errors_omitted, &$counts, $row_number, $message ) {
		$counts['failed']++;

		if ( count( $errors ) < self::MAX_ERRORS ) {
			$errors[] = array(
				'row'     => $row_number,
				'message' => $message,
			);
		} else {
			$errors_omitted = true;
		}
	}

	/**
	 * Return the standard authorization error.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'smartsused_unit_import_forbidden',
			__( 'Na hromadný import bytových jednotiek nemáte oprávnenie.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}
}
