<?php
/**
 * Imports SmartSused owners and tenants from CSV or Excel files.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and processes manager-only resident imports.
 */
final class SmartSused_Owner_Import {

	const MAX_FILE_SIZE = 5242880;
	const MAX_ROWS      = 5000;
	const MAX_ERRORS    = 100;

	/**
	 * Register the legacy owners route and the residents route.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register both import route names for backwards compatibility.
	 */
	public function register_routes() {
		foreach ( array( 'owners', 'residents' ) as $route ) {
			register_rest_route(
				'smartsused/v1',
				'/import/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_residents' ),
					'permission_callback' => array( $this, 'permission_to_import' ),
				)
			);
		}
	}

	/**
	 * Require an authenticated manager with a verified JWT.
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
	 * Import owners and tenants using the manager's assigned properties.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_residents( $request ) {
		global $wpdb;

		$files  = $request->get_file_params();
		$upload = isset( $files['file'] ) ? $files['file'] : null;
		$data   = SmartSused_Import_File::read_rows( $upload, self::MAX_FILE_SIZE, self::MAX_ROWS, 7 );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$roles = array(
			'owner'  => array( 'slug' => 'smartsused_owner', 'label' => __( 'Vlastník', 'smartsused-core' ) ),
			'tenant' => array( 'slug' => 'smartsused_tenant', 'label' => __( 'Nájomca', 'smartsused-core' ) ),
		);

		foreach ( $roles as $role ) {
			if ( null === get_role( $role['slug'] ) ) {
				return new WP_Error(
					'smartsused_resident_role_missing',
					__( 'Rola vlastníka alebo nájomcu nie je nakonfigurovaná. Znova aktivujte SmartSused Core.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
			}
		}

		$header       = SmartSused_Import_File::normalize_headers( $data['header'] );
		$property_ids = $this->get_import_property_ids();
		if ( is_wp_error( $property_ids ) ) {
			return $property_ids;
		}

		$single_property = 1 === count( $property_ids );
		$expected_header = $single_property
			? array( 'meno', 'priezvisko', 'email', 'telefon', 'cislo_bytu', 'typ_obyvatela' )
			: array( 'nazov_domu', 'meno', 'priezvisko', 'email', 'telefon', 'cislo_bytu', 'typ_obyvatela' );

		if ( empty( $property_ids ) || $expected_header !== $header ) {
			return new WP_Error(
				'smartsused_resident_import_invalid_header',
				$single_property
					? __( 'Súbor musí obsahovať stĺpce v tomto poradí: Meno;Priezvisko;E-mail;Telefón;Číslo bytu;Typ obyvateľa.', 'smartsused-core' )
					: __( 'Súbor musí obsahovať stĺpce v tomto poradí: Názov bytového domu;Meno;Priezvisko;E-mail;Telefón;Číslo bytu;Typ obyvateľa.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$counts = array(
			'imported'          => 0,
			'duplicates_skipped' => 0,
			'failed'             => 0,
			'email_failed'       => 0,
		);
		$errors         = array();
		$errors_omitted = false;
		$property_map = $single_property ? array() : $this->get_import_property_map( $property_ids );
		if ( is_wp_error( $property_map ) ) {
			return $property_map;
		}
		$single_property_id = $single_property ? (int) $property_ids[0] : 0;
		$unit_option_maps   = array();

		if ( $single_property ) {
			$unit_option_maps[ $single_property_id ] = $this->get_resident_unit_option_map( $single_property_id );
			if ( is_wp_error( $unit_option_maps[ $single_property_id ] ) ) {
				return $unit_option_maps[ $single_property_id ];
			}
		}

		$relationship_table = $wpdb->prefix . 'smartsused_unit_relationships';

		foreach ( $data['rows'] as $entry ) {
			$row_number = $entry['number'];
			$row        = $entry['values'];

			if ( is_array( $row ) && $this->is_empty_resident_row( $row ) ) {
				continue;
			}

			if ( ! is_array( $row ) || count( $header ) !== count( $row ) ) {
				$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Riadok obsahuje nesprávny počet stĺpcov.', 'smartsused-core' ) );
				continue;
			}

			$values = array_map(
				static function ( $value ) {
					return is_scalar( $value ) ? trim( (string) $value ) : '';
				},
				$row
			);

			$offset = $single_property ? 0 : 1;
			$property_id = $single_property_id;
			if ( ! $single_property ) {
				$property_name = sanitize_text_field( $values[0] );
				$property_key  = self::normalize_lookup_label( $property_name );
				$matching_property_ids = isset( $property_map[ $property_key ] ) ? $property_map[ $property_key ] : array();

				if ( 1 !== count( $matching_property_ids ) ) {
					$this->record_error(
						$errors,
						$errors_omitted,
						$counts,
						$row_number,
						empty( $matching_property_ids )
							? __( 'Bytový dom sa nenašiel alebo nie je priradený k vášmu účtu.', 'smartsused-core' )
							: __( 'Názov bytového domu nie je jednoznačný. Vyberte presný názov z rozbaľovacieho zoznamu.', 'smartsused-core' )
					);
					continue;
				}

				$property_id = (int) $matching_property_ids[0];
			}

			$first_name    = sanitize_text_field( $values[ $offset ] );
			$last_name     = sanitize_text_field( $values[ $offset + 1 ] );
			$email         = strtolower( sanitize_email( $values[ $offset + 2 ] ) );
			$phone         = sanitize_text_field( $values[ $offset + 3 ] );
			$unit_label    = sanitize_text_field( $values[ $offset + 4 ] );
			$resident_type = self::normalize_resident_type( $values[ $offset + 5 ] );

			if (
				'' === $first_name
				|| '' === $last_name
				|| ! is_email( $email )
				|| '' === $unit_label
				|| '' === $resident_type
			) {
				$this->record_error(
					$errors,
					$errors_omitted,
					$counts,
					$row_number,
					__( 'Vyplňte platné meno, priezvisko, e-mail, číslo bytu a typ obyvateľa.', 'smartsused-core' )
				);
				continue;
			}

			if ( '' !== $phone && ! SmartSused_Validation::is_valid_phone( $phone ) ) {
				$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Telefónne číslo musí byť v platnom slovenskom alebo českom formáte.', 'smartsused-core' ) );
				continue;
			}

			if ( ! isset( $unit_option_maps[ $property_id ] ) ) {
				$unit_option_maps[ $property_id ] = $this->get_resident_unit_option_map( $property_id );
			}
			if ( is_wp_error( $unit_option_maps[ $property_id ] ) ) {
				$this->record_error( $errors, $errors_omitted, $counts, $row_number, $unit_option_maps[ $property_id ]->get_error_message() );
				continue;
			}

			$unit_key = self::normalize_lookup_label( $unit_label );
			$unit_ids = isset( $unit_option_maps[ $property_id ][ $unit_key ] ) ? $unit_option_maps[ $property_id ][ $unit_key ] : array();

			if ( empty( $unit_ids ) ) {
				$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Číslo bytu sa nenašlo. Vyberte byt z rozbaľovacieho zoznamu šablóny.', 'smartsused-core' ) );
				continue;
			}

			if ( 1 !== count( $unit_ids ) ) {
				$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Číslo bytu nie je jednoznačné. Použite šablónu pre priradený dom alebo kontaktujte administrátora.', 'smartsused-core' ) );
				continue;
			}

			$unit_id = (int) $unit_ids[0];
			if (
				$property_id < 1
				|| ! in_array( $property_id, $property_ids, true )
				|| $property_id !== (int) wp_get_post_parent_id( $unit_id )
			) {
				$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Importovať možno iba obyvateľov bytových domov priradených k vášmu účtu.', 'smartsused-core' ) );
				continue;
			}

			$role        = $roles[ $resident_type ];
			$user_id     = email_exists( $email );
			$new_user    = false;
			$role_slug   = $role['slug'];

			if ( false === $user_id ) {
				if ( strlen( $email ) > 60 || username_exists( $email ) ) {
					$this->record_error(
						$errors,
						$errors_omitted,
						$counts,
						$row_number,
						__( 'E-mail je príliš dlhý alebo sa už používa ako prihlasovacie meno.', 'smartsused-core' )
					);
					continue;
				}

				$user_id = wp_insert_user(
					array(
						'user_login' => $email,
						'user_email' => $email,
						'user_pass'  => wp_generate_password( 32, true, true ),
						'first_name' => $first_name,
						'last_name'  => $last_name,
						'role'       => $role_slug,
					)
				);

				if ( is_wp_error( $user_id ) || ! is_int( $user_id ) || $user_id < 1 ) {
					$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Používateľský účet sa nepodarilo vytvoriť.', 'smartsused-core' ) );
					continue;
				}

				$new_user = true;
			} else {
				$user_id = absint( $user_id );
				$user    = get_userdata( $user_id );

				if ( ! ( $user instanceof WP_User ) ) {
					$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Existujúci používateľ sa nepodarilo načítať.', 'smartsused-core' ) );
					continue;
				}

				if ( ! in_array( $role_slug, $user->roles, true ) ) {
					$user->add_role( $role_slug );
					$user = get_userdata( $user_id );
				}

				if ( ! ( $user instanceof WP_User ) || ! in_array( $role_slug, $user->roles, true ) ) {
					$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Používateľovi sa nepodarilo priradiť požadovanú rolu rezidenta.', 'smartsused-core' ) );
					continue;
				}
			}

			if ( '' !== $phone && ! update_user_meta( $user_id, 'smartsused_phone', $phone ) ) {
				if ( $phone !== get_user_meta( $user_id, 'smartsused_phone', true ) ) {
					$this->cleanup_failed_user_creation( $user_id, $new_user );
					$this->record_error( $errors, $errors_omitted, $counts, $row_number, __( 'Telefónne číslo sa nepodarilo uložiť.', 'smartsused-core' ) );
					continue;
				}
			}

			$relationship = $this->save_relationship( $relationship_table, $unit_id, $user_id, $resident_type );

			if ( is_wp_error( $relationship ) ) {
				$this->cleanup_failed_user_creation( $user_id, $new_user );
				$this->record_error( $errors, $errors_omitted, $counts, $row_number, $relationship->get_error_message() );
				continue;
			}

			if ( 'duplicate' === $relationship ) {
				++$counts['duplicates_skipped'];
				continue;
			}

			++$counts['imported'];

			if ( ! smartsused_send_welcome_email( $user_id, $role_slug ) ) {
				++$counts['email_failed'];
				$this->record_detail(
					$errors,
					$errors_omitted,
					$row_number,
					__( 'Rezident bol priradený, ale uvítací e-mail sa nepodarilo odoslať.', 'smartsused-core' )
				);
			}
		}

		return new WP_REST_Response(
			array(
				'success'            => true,
				'message'            => sprintf(
					/* translators: 1: imported count, 2: duplicate count, 3: failed row count, 4: failed email count. */
					__( 'Import dokončený. Priradené: %1$d, existujúce vzťahy: %2$d, chybné riadky: %3$d, neodoslané e-maily: %4$d.', 'smartsused-core' ),
					$counts['imported'],
					$counts['duplicates_skipped'],
					$counts['failed'],
					$counts['email_failed']
				),
				'imported'           => $counts['imported'],
				'duplicates_skipped' => $counts['duplicates_skipped'],
				'failed'             => $counts['failed'],
				'email_failed'       => $counts['email_failed'],
				'errors'             => $errors,
				'errors_omitted'     => $errors_omitted,
			),
			200
		);
	}

	/**
	 * Normalize the localized Excel dropdown value.
	 *
	 * @param string $value Resident label.
	 * @return string
	 */
	private static function normalize_resident_type( $value ) {
		$value = function_exists( 'remove_accents' ) ? remove_accents( trim( $value ) ) : trim( $value );
		$value = strtolower( $value );

		if ( 'vlastnik' === $value ) {
			return 'owner';
		}

		if ( 'najomca' === $value ) {
			return 'tenant';
		}

		return '';
	}

	/**
	 * Ignore completely blank rows in the shared resident template.
	 *
	 * @param array $row Resident template row values.
	 * @return bool
	 */
	private function is_empty_resident_row( $row ) {
		foreach ( $row as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the published properties available to the current importing manager.
	 *
	 * @return int[]|WP_Error
	 */
	private function get_import_property_ids() {
		global $wpdb;

		if ( SmartSused_Manager_Scope::is_scoped_manager() ) {
			$property_ids = SmartSused_Manager_Scope::get_property_ids();
			return empty( $property_ids )
				? new WP_Error(
					'smartsused_resident_property_assignment_missing',
					__( 'K účtu správcu nie je priradený žiadny publikovaný bytový dom.', 'smartsused-core' ),
					array( 'status' => 403 )
				)
				: $property_ids;
		}

		$property_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = %s
				ORDER BY ID ASC LIMIT %d",
				'smartsused_property',
				'publish',
				SmartSused_Import_Template::MAX_PROPERTY_UNITS + 1
			)
		);

		if ( ! is_array( $property_ids ) ) {
			error_log( 'SmartSused Core: Resident import properties could not be loaded.' );
			return new WP_Error(
				'smartsused_resident_properties_query_failed',
				__( 'Zoznam bytových domov sa nepodarilo načítať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( count( $property_ids ) > SmartSused_Import_Template::MAX_PROPERTY_UNITS ) {
			return new WP_Error(
				'smartsused_resident_properties_limit',
				__( 'Zoznam bytových domov je príliš veľký na bezpečný import.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		return array_map( 'intval', $property_ids );
	}

	/**
	 * Map normalized property names to IDs within the manager's allowed scope.
	 *
	 * @param int[] $property_ids Allowed property IDs.
	 * @return array|WP_Error
	 */
	private function get_import_property_map( $property_ids ) {
		global $wpdb;

		$property_ids = array_values( array_unique( array_map( 'absint', $property_ids ) ) );
		if ( empty( $property_ids ) ) {
			return array();
		}

		$properties = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = %s
					AND ID IN (" . implode( ',', array_fill( 0, count( $property_ids ), '%d' ) ) . ')
				ORDER BY post_title ASC, ID ASC',
				array_merge( array( 'smartsused_property', 'publish' ), $property_ids )
			),
			ARRAY_A
		);

		if ( ! is_array( $properties ) ) {
			error_log( 'SmartSused Core: Resident import property map could not be loaded.' );
			return new WP_Error(
				'smartsused_resident_properties_query_failed',
				__( 'Zoznam bytových domov sa nepodarilo načítať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$property_map = array();
		foreach ( $properties as $property ) {
			$key = self::normalize_lookup_label( $property['post_title'] );
			if ( ! isset( $property_map[ $key ] ) ) {
				$property_map[ $key ] = array();
			}
			$property_map[ $key ][] = (int) $property['ID'];
		}

		return $property_map;
	}

	/**
	 * Build a bounded map of visible unit labels to published unit IDs.
	 *
	 * @param int $property_id Optional property scope.
	 * @return array|WP_Error
	 */
	private function get_resident_unit_option_map( $property_id ) {
		global $wpdb;

		$property_filter = $property_id > 0 ? ' AND unit.post_parent = %d' : '';
		$parameters      = array(
			'_vchod',
			'smartsused_property',
			'publish',
			'smartsused_unit',
			'publish',
		);
		if ( $property_id > 0 ) {
			$parameters[] = $property_id;
		}
		$parameters[] = SmartSused_Import_Template::MAX_PROPERTY_UNITS + 1;

		$units = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT unit.ID, unit.post_title, property.post_title AS property_title, COALESCE(
					( SELECT entrance.meta_value
						FROM {$wpdb->postmeta} AS entrance
						WHERE entrance.post_id = unit.ID AND entrance.meta_key = %s
						ORDER BY entrance.meta_id ASC LIMIT 1 ),
					''
				) AS entrance
				FROM {$wpdb->posts} AS unit
				INNER JOIN {$wpdb->posts} AS property ON property.ID = unit.post_parent
					AND property.post_type = %s AND property.post_status = %s
				WHERE unit.post_type = %s AND unit.post_status = %s{$property_filter}
				ORDER BY property.post_title ASC, unit.post_title ASC
				LIMIT %d",
				$parameters
			),
			ARRAY_A
		);

		if ( ! is_array( $units ) ) {
			error_log( 'SmartSused Core: Resident import unit options could not be loaded.' );
			return new WP_Error(
				'smartsused_resident_unit_lookup_failed',
				__( 'Zoznam bytových jednotiek sa nepodarilo načítať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( count( $units ) > SmartSused_Import_Template::MAX_PROPERTY_UNITS ) {
			return new WP_Error(
				'smartsused_resident_unit_limit',
				__( 'Zoznam bytových jednotiek je príliš veľký. Požiadajte administrátora o priradenie správcu k domu.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$map     = array();
		foreach ( $units as $unit ) {
			$labels = array(
				SmartSused_Import_Template::format_unit_option( $unit['post_title'], $unit['entrance'] ),
				(string) $unit['post_title'],
			);

			if ( 1 === preg_match( '/(?:^|,\s*)Byt\s+(.+)$/u', (string) $unit['post_title'], $matches ) ) {
				$labels[] = trim( $matches[1] );
			} elseif ( 1 === preg_match( '/^Byt\s+(.+)$/u', (string) $unit['post_title'], $matches ) ) {
				$labels[] = trim( $matches[1] );
			}

			foreach ( array_unique( $labels ) as $label ) {
				$key = self::normalize_lookup_label( $label );
				if ( ! isset( $map[ $key ] ) ) {
					$map[ $key ] = array();
				}
				$map[ $key ][] = (int) $unit['ID'];
			}
		}

		foreach ( $map as &$unit_ids ) {
			$unit_ids = array_values( array_unique( $unit_ids ) );
		}
		unset( $unit_ids );

		return $map;
	}

	/**
	 * Normalize visible unit labels for case- and accent-insensitive matching.
	 *
	 * @param string $label Unit label.
	 * @return string
	 */
	private static function normalize_lookup_label( $label ) {
		$label = trim( (string) $label );
		$label = function_exists( 'remove_accents' ) ? remove_accents( $label ) : $label;
		return strtolower( $label );
	}

	/**
	 * Resolve a contextual dropdown label to one published unit.
	 *
	 * The unit list is queried and normalized once per property for the whole
	 * import so large files do not issue one query per resident row.
	 *
	 * @param string $property_name Property title.
	 * @param string $unit_label    Human-readable dropdown value.
	 * @param array  $cache         Per-property label lookup cache.
	 * @return int|WP_Error
	 */
	private function find_unit_id_by_label( $property_name, $unit_label, &$cache ) {
		global $wpdb;

		if ( ! array_key_exists( $property_name, $cache ) ) {
			$property_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_title = %s ORDER BY ID ASC LIMIT 2",
					'smartsused_property',
					'publish',
					$property_name
				)
			);

			if ( empty( $property_ids ) ) {
				$cache[ $property_name ] = new WP_Error(
					'smartsused_resident_property_not_found',
					__( 'Bytový dom s uvedeným názvom sa nenašiel alebo nie je publikovaný.', 'smartsused-core' )
				);
			} elseif ( 1 !== count( $property_ids ) ) {
				$cache[ $property_name ] = new WP_Error(
					'smartsused_resident_property_ambiguous',
					__( 'Názov bytového domu nie je jednoznačný. Rozlíšte dom úplným názvom.', 'smartsused-core' )
				);
			} else {
				$property_id = (int) $property_ids[0];
				$units       = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT unit.ID, unit.post_title, COALESCE(
							( SELECT entrance.meta_value
								FROM {$wpdb->postmeta} AS entrance
								WHERE entrance.post_id = unit.ID AND entrance.meta_key = %s
								ORDER BY entrance.meta_id ASC LIMIT 1 ),
							''
						) AS entrance
						FROM {$wpdb->posts} AS unit
						WHERE unit.post_type = %s AND unit.post_status = %s AND unit.post_parent = %d
						ORDER BY unit.ID ASC
						LIMIT %d",
						'_vchod',
						'smartsused_unit',
						'publish',
						$property_id,
						10001
					),
					ARRAY_A
				);

				if ( ! is_array( $units ) ) {
					error_log( 'SmartSused Core: Unit dropdown values could not be resolved during resident import.' );
					$cache[ $property_name ] = new WP_Error(
						'smartsused_resident_unit_lookup_failed',
						__( 'Zoznam bytov pre zvolený dom sa nepodarilo načítať.', 'smartsused-core' )
					);
				} elseif ( count( $units ) > 10000 ) {
					$cache[ $property_name ] = new WP_Error(
						'smartsused_resident_unit_limit',
						__( 'Dom obsahuje priveľa bytov na spracovanie kontextovej šablóny.', 'smartsused-core' )
					);
				} else {
					$label_map = array();

					foreach ( $units as $unit ) {
						$label = SmartSused_Import_Template::format_unit_option(
							$unit['post_title'],
							$unit['entrance']
						);

						if ( ! isset( $label_map[ $label ] ) ) {
							$label_map[ $label ] = array();
						}

						$label_map[ $label ][] = (int) $unit['ID'];
					}

					$cache[ $property_name ] = $label_map;
				}
			}
		}

		$property_options = $cache[ $property_name ];

		if ( is_wp_error( $property_options ) ) {
			return $property_options;
		}

		if ( ! isset( $property_options[ $unit_label ] ) ) {
			return new WP_Error(
				'smartsused_resident_unit_not_found',
				__( 'Vyberte bytovú jednotku z rozbaľovacieho zoznamu pre zvolený dom.', 'smartsused-core' )
			);
		}

		if ( 1 !== count( $property_options[ $unit_label ] ) ) {
			return new WP_Error(
				'smartsused_resident_unit_ambiguous',
				__( 'Zvolený byt nie je jednoznačný. Kontaktujte správcu domu.', 'smartsused-core' )
			);
		}

		return $property_options[ $unit_label ][0];
	}

	/**
	 * Add or update the resident's unit relationship under a per-pair lock.
	 *
	 * @param string $table         Relationship table.
	 * @param int    $unit_id       Unit ID.
	 * @param int    $user_id       User ID.
	 * @param string $resident_type Relationship type.
	 * @return string|WP_Error "saved", "duplicate", or an error.
	 */
	private function save_relationship( $table, $unit_id, $user_id, $resident_type ) {
		global $wpdb;

		$lock_name = 'smartsused_resident_' . md5( $unit_id . ':' . $user_id );
		$lock      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );

		if ( '1' !== (string) $lock ) {
			return new WP_Error(
				'smartsused_resident_lock_failed',
				__( 'Priradenie rezidenta sa nepodarilo bezpečne rezervovať.', 'smartsused-core' )
			);
		}

		try {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, resident_type FROM {$table} WHERE unit_id = %d AND user_id = %d LIMIT 1",
					$unit_id,
					$user_id
				),
				ARRAY_A
			);

			if ( is_array( $existing ) && $resident_type === $existing['resident_type'] ) {
				return 'duplicate';
			}

			if ( is_array( $existing ) ) {
				$written = $wpdb->update(
					$table,
					array( 'resident_type' => $resident_type ),
					array( 'id' => (int) $existing['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			} else {
				$written = $wpdb->insert(
					$table,
					array(
						'unit_id'       => $unit_id,
						'user_id'       => $user_id,
						'resident_type' => $resident_type,
						'created_at'    => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}

			if ( false !== $written ) {
				return 'saved';
			}

			$stored_type = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT resident_type FROM {$table} WHERE unit_id = %d AND user_id = %d LIMIT 1",
					$unit_id,
					$user_id
				)
			);

			if ( $resident_type === $stored_type ) {
				return 'duplicate';
			}

			return new WP_Error(
				'smartsused_resident_relationship_failed',
				__( 'Vzťah rezidenta k bytovej jednotke sa nepodarilo uložiť.', 'smartsused-core' )
			);
		} finally {
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

			if ( '1' !== (string) $released ) {
				error_log( 'SmartSused Core: Resident import lock could not be released.' );
			}
		}
	}

	/**
	 * Resolve a published unit by property name, apartment number, and entrance.
	 *
	 * @param string $property_name Property title.
	 * @param string $unit_number   Apartment number.
	 * @param string $entrance      Entrance label.
	 * @return int|WP_Error
	 */
	private function find_unit_id( $property_name, $unit_number, $entrance ) {
		global $wpdb;

		$property_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_title = %s ORDER BY ID ASC LIMIT 2",
				'smartsused_property',
				'publish',
				$property_name
			)
		);

		if ( empty( $property_ids ) ) {
			return new WP_Error( 'smartsused_resident_property_not_found', __( 'Bytový dom s uvedeným názvom sa nenašiel alebo nie je publikovaný.', 'smartsused-core' ) );
		}

		if ( 1 !== count( $property_ids ) ) {
			return new WP_Error( 'smartsused_resident_property_ambiguous', __( 'Názov bytového domu nie je jednoznačný. Rozlíšte dom úplným názvom.', 'smartsused-core' ) );
		}

		$property_id  = (int) $property_ids[0];
		$manual_title = 'Byt ' . $unit_number;
		$import_title = sprintf( 'Vchod %1$s, Byt %2$s', $entrance, $unit_number );
		$unit_ids     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT unit.ID
				FROM {$wpdb->posts} AS unit
				LEFT JOIN {$wpdb->postmeta} AS entrance_meta
					ON entrance_meta.post_id = unit.ID AND entrance_meta.meta_key = %s
				WHERE unit.post_type = %s AND unit.post_status = %s AND unit.post_parent = %d
					AND ((unit.post_title = %s AND entrance_meta.meta_value = %s) OR unit.post_title = %s)
				ORDER BY unit.ID ASC LIMIT 2",
				'_vchod',
				'smartsused_unit',
				'publish',
				$property_id,
				$manual_title,
				$entrance,
				$import_title
			)
		);

		if ( empty( $unit_ids ) ) {
			return new WP_Error( 'smartsused_resident_unit_not_found', __( 'Bytová jednotka s uvedeným domom, číslom bytu a vchodom sa nenašla.', 'smartsused-core' ) );
		}

		if ( 1 !== count( $unit_ids ) ) {
			return new WP_Error( 'smartsused_resident_unit_ambiguous', __( 'Bytová jednotka nie je jednoznačná. Skontrolujte názov domu, číslo bytu a vchod.', 'smartsused-core' ) );
		}

		return (int) $unit_ids[0];
	}

	/**
	 * Record a failed row while bounding the response details.
	 *
	 * @param array  $errors         Collected errors.
	 * @param bool   $errors_omitted Whether details were truncated.
	 * @param array  $counts         Import counters.
	 * @param int    $row_number     Source row number.
	 * @param string $message        Localized error message.
	 */
	private function record_error( &$errors, &$errors_omitted, &$counts, $row_number, $message ) {
		++$counts['failed'];
		$this->record_detail( $errors, $errors_omitted, $row_number, $message );
	}

	/**
	 * Record an error detail without changing the row failure count.
	 *
	 * @param array  $errors         Collected errors.
	 * @param bool   $errors_omitted Whether details were truncated.
	 * @param int    $row_number     Source row number.
	 * @param string $message        Localized error message.
	 */
	private function record_detail( &$errors, &$errors_omitted, $row_number, $message ) {
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
	 * Delete only an account created by the current failed import row.
	 *
	 * @param int  $user_id  User ID.
	 * @param bool $new_user Whether the row created this account.
	 */
	private function cleanup_failed_user_creation( $user_id, $new_user ) {
		if ( ! $new_user ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( ! wp_delete_user( $user_id ) ) {
			error_log( 'SmartSused Core: A newly imported resident could not be removed after row failure.' );
		}
	}

	/**
	 * Return the standard import authorization error.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'smartsused_resident_import_forbidden',
			__( 'Na import vlastníkov a nájomcov nemáte oprávnenie.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}
}
