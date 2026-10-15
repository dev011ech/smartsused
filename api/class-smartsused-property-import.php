<?php
/**
 * Imports SmartSused properties from CSV files.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and processes the manager-only property CSV import endpoint.
 */
final class SmartSused_Property_Import {

	const MAX_FILE_SIZE = 5242880;
	const MAX_ROWS      = 5000;

	/**
	 * Register the property import endpoint.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/import/properties',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_properties' ),
				'permission_callback' => array( $this, 'permission_to_import' ),
			)
		);
	}

	/**
	 * Require a manager with a cryptographically verified JWT.
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
			|| SmartSused_Manager_Scope::is_scoped_manager( $user_id )
		) {
			return self::forbidden_error();
		}

		return true;
	}

	/**
	 * Import property names and addresses from a semicolon-delimited file.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_properties( $request ) {
		global $wpdb;

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) ? $files['file'] : null;

		$data = SmartSused_Import_File::read_rows( $file, self::MAX_FILE_SIZE, self::MAX_ROWS, 2 );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $file ) ) {
			return new WP_Error(
				'smartsused_invalid_property_csv',
				__( 'Nahrajte platný súbor s príponou .csv alebo .xlsx.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$header = SmartSused_Import_File::normalize_headers( $data['header'] );

		$expected_header = array( 'nazov_domu', 'adresa' );

		if ( $expected_header !== $header ) {
			return new WP_Error(
				'smartsused_property_csv_invalid_header',
				__( 'Súbor musí obsahovať stĺpce v poradí: Názov bytového domu;Adresa.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$created    = 0;
		$duplicates = 0;
		$failed     = 0;
		$errors     = array();
		$row_number = 1;

		foreach ( $data['rows'] as $entry ) {
			$row_number = $entry['number'];
			$row        = $entry['values'];

			if ( ! is_array( $row ) || 2 !== count( $row ) ) {
				++$failed;
				if ( count( $errors ) < 100 ) {
					$errors[] = array(
						'row'     => $row_number,
						'message' => __( 'Riadok musí obsahovať presne dva stĺpce.', 'smartsused-core' ),
					);
				}
				continue;
			}

			$name    = sanitize_text_field( trim( (string) $row[0] ) );
			$address = sanitize_text_field( trim( (string) $row[1] ) );

			if ( '' === $name || '' === $address ) {
				++$failed;
				if ( count( $errors ) < 100 ) {
					$errors[] = array(
						'row'     => $row_number,
						'message' => __( 'Vyplňte názov domu aj jeho adresu.', 'smartsused-core' ),
					);
				}
				continue;
			}

			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_title = %s LIMIT 1",
					'smartsused_property',
					'publish',
					$name
				)
			);

			if ( $existing ) {
				++$duplicates;
				continue;
			}

			$property_id = wp_insert_post(
				array(
					'post_type'    => 'smartsused_property',
					'post_title'   => $name,
					'post_content' => $address,
					'post_status'  => 'publish',
				),
				true
			);

			if ( is_wp_error( $property_id ) || ! is_int( $property_id ) || $property_id < 1 ) {
				++$failed;
				if ( count( $errors ) < 100 ) {
					$errors[] = array(
						'row'     => $row_number,
						'message' => __( 'Bytový dom sa nepodarilo vytvoriť.', 'smartsused-core' ),
					);
				}
				continue;
			}

			++$created;
		}

		return new WP_REST_Response(
			array(
				'success'           => true,
				'message'           => sprintf(
					/* translators: 1: created property count, 2: duplicates, 3: failed rows. */
					__( 'Import bol dokončený. Vytvorené domy: %1$d, preskočené duplicity: %2$d, chybné riadky: %3$d.', 'smartsused-core' ),
					$created,
					$duplicates,
					$failed
				),
				'created'           => $created,
				'duplicates_skipped' => $duplicates,
				'failed'            => $failed,
				'errors'            => array_slice( $errors, 0, 100 ),
				'errors_omitted'    => $failed > count( $errors ),
			),
			200
		);
	}

	/**
	 * Validate uploaded CSV metadata and content type.
	 *
	 * @param array $file Uploaded file.
	 * @return bool
	 */
	private function is_valid_upload( $file ) {
		if (
			! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] )
			|| UPLOAD_ERR_OK !== (int) $file['error']
			|| ! is_string( $file['tmp_name'] )
			|| ! is_uploaded_file( $file['tmp_name'] )
			|| 'csv' !== strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) )
			|| ! is_numeric( $file['size'] )
			|| (int) $file['size'] < 1
			|| (int) $file['size'] > self::MAX_FILE_SIZE
			|| ! is_readable( $file['tmp_name'] )
			|| (int) filesize( $file['tmp_name'] ) !== (int) $file['size']
			|| ! function_exists( 'finfo_open' )
		) {
			return false;
		}

		$file_info = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $file_info ) {
			return false;
		}

		$mime_type = finfo_file( $file_info, $file['tmp_name'] );
		unset( $file_info );

		return is_string( $mime_type )
			&& in_array( $mime_type, array( 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel' ), true );
	}

	/**
	 * Return the standard manager-only import permission error.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'smartsused_property_import_forbidden',
			__( 'Na import bytových domov nemáte oprávnenie.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}
}
