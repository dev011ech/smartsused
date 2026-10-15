<?php
/**
 * Generates formatted Excel templates for SmartSused imports.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register and serve manager-only XLSX import templates.
 */
final class SmartSused_Import_Template {

	/**
	 * Human-readable headers and widths for supported templates.
	 *
	 * @var array<string, array<int, array{0: string, 1: int}>>
	 */
	private const COLUMNS = array(
		'properties' => array(
			array( 'Názov bytového domu', 34 ),
			array( 'Adresa', 48 ),
		),
		'units'      => array(
			array( 'Názov bytového domu', 34 ),
			array( 'Číslo bytu', 18 ),
			array( 'Poschodie', 16 ),
			array( 'Vchod', 18 ),
		),
		'residents'  => array(
			array( 'Meno', 20 ),
			array( 'Priezvisko', 24 ),
			array( 'E-mail', 36 ),
			array( 'Telefón', 22 ),
			array( 'Číslo bytu', 34 ),
			array( 'Typ obyvateľa', 22 ),
		),
	);

	/**
	 * Number of blank rows styled in the downloadable workbook.
	 */
	private const TEMPLATE_ROWS = 100;
	const MAX_PROPERTY_UNITS = 10000;

	/**
	 * Register the route and raw XLSX response handler.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_xlsx_response' ), 10, 4 );
	}

	/**
	 * Register the Excel template endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'smartsused/v1',
			'/import/template',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_template' ),
				'permission_callback' => array( $this, 'permission_to_download' ),
				'args'                => array(
					'type' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'properties', 'units', 'owners', 'residents' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'property_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return 0 === (int) $value || (int) $value > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Require a manager with an explicitly verified JWT.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permission_to_download( $request ) {
		$authorization = $request->get_header( 'authorization' );
		$user_id       = get_current_user_id();
		$user          = get_userdata( $user_id );

		if (
			! is_string( $authorization )
			|| 1 !== preg_match( '/^Bearer\s+\S+$/i', $authorization )
			|| ! is_user_logged_in()
			|| $user_id < 1
			|| ! ( $user instanceof WP_User )
			|| ! in_array( 'smartsused_manager', $user->roles, true )
			|| ( SmartSused_Manager_Scope::is_scoped_manager( $user_id ) && empty( SmartSused_Manager_Scope::get_property_ids( $user_id ) ) )
		) {
			return self::forbidden_error();
		}

		$verified_user_id = apply_filters( 'smartsused_verified_jwt_user_id', 0, $request );

		if ( ! is_int( $verified_user_id ) || $verified_user_id !== $user_id ) {
			return self::forbidden_error();
		}

		return true;
	}

	/**
	 * Build a styled XLSX template in memory.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_template( $request ) {
		$type        = sanitize_key( $request->get_param( 'type' ) );
		$property_id = absint( $request->get_param( 'property_id' ) );

		if ( 'owners' === $type ) {
			$type = 'residents';
		}

		if ( ! isset( self::COLUMNS[ $type ] ) ) {
			return new WP_Error(
				'smartsused_template_type_invalid',
				__( 'Požadovaný typ Excel šablóny nie je podporovaný.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		if ( $property_id > 0 && 'residents' !== $type ) {
			return new WP_Error(
				'smartsused_template_context_invalid',
				__( 'Bytový dom možno zadať iba pri šablóne obyvateľov.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$resident_properties = array();
		if ( 'residents' === $type ) {
			if ( SmartSused_Manager_Scope::is_scoped_manager() ) {
				$assigned_property_ids = SmartSused_Manager_Scope::get_property_ids();
				if ( empty( $assigned_property_ids ) ) {
					return new WP_Error(
						'smartsused_template_property_forbidden',
						__( 'K účtu správcu nie je priradený žiadny publikovaný bytový dom.', 'smartsused-core' ),
						array( 'status' => 403 )
					);
				}

				if ( $property_id > 0 && ! in_array( $property_id, $assigned_property_ids, true ) ) {
					return new WP_Error(
						'smartsused_template_property_forbidden',
						__( 'Šablónu možno vytvoriť iba pre bytový dom priradený k vášmu účtu.', 'smartsused-core' ),
						array( 'status' => 403 )
					);
				}

				$resident_properties = $this->get_resident_properties( $assigned_property_ids );
			} elseif ( $property_id > 0 ) {
				$resident_properties = $this->get_resident_properties( array( $property_id ) );
			} else {
				$resident_properties = $this->get_resident_properties( array() );
			}

			if ( is_wp_error( $resident_properties ) ) {
				return $resident_properties;
			}

			if ( empty( $resident_properties ) ) {
				return new WP_Error(
					'smartsused_template_property_not_found',
					__( 'Pre vytvorenie šablóny nie sú dostupné publikované bytové domy.', 'smartsused-core' ),
					array( 'status' => 404 )
				);
			}

			$property_name_keys = array();
			foreach ( $resident_properties as $property ) {
				$key = strtolower( remove_accents( trim( (string) $property['post_title'] ) ) );
				if ( isset( $property_name_keys[ $key ] ) ) {
					return new WP_Error(
						'smartsused_template_duplicate_property_names',
						__( 'Viac priradených bytových domov má rovnaký názov. Premenujte domy, aby ich bolo možné jednoznačne vybrať.', 'smartsused-core' ),
						array( 'status' => 400 )
					);
				}
				$property_name_keys[ $key ] = true;
			}
		}

		if (
			! class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' )
			|| ! class_exists( 'ZipArchive' )
			|| ! extension_loaded( 'gd' )
		) {
			return new WP_Error(
				'smartsused_template_dependency_missing',
				__( 'Generovanie Excel súborov nie je dostupné. Skontrolujte PhpSpreadsheet a PHP rozšírenia ZIP a GD.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'residents' === $type ? 'Obyvatelia' : 'Import SmartSused' );
		$columns = self::COLUMNS[ $type ];
		if ( 'residents' === $type && count( $resident_properties ) > 1 ) {
			array_unshift( $columns, array( 'Názov bytového domu', 38 ) );
		}

		$template_rows = 'residents' === $type ? SmartSused_Owner_Import::MAX_ROWS : self::TEMPLATE_ROWS;
		$headers = array_column( $columns, 0 );
		$column_count = count( $headers );
		$last_column  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $column_count );

		$spreadsheet->getProperties()
			->setCreator( 'SmartSused' )
			->setTitle( 'residents' === $type ? 'Obyvatelia' : 'Import SmartSused' )
			->setSubject( 'residents' === $type ? 'Šablóna na import obyvateľov' : 'Import bytových domov a jednotiek' );

		$sheet->fromArray( $headers, null, 'A1' );
		$sheet->freezePane( 'A2' );
		$sheet->setAutoFilter( 'A1:' . $last_column . '1' );
		$sheet->getRowDimension( 1 )->setRowHeight( 30 );
		$sheet->getStyle( 'A1:' . $last_column . '1' )->applyFromArray(
			array(
				'font'      => array(
					'bold'  => true,
					'color' => array( 'rgb' => 'FFFFFF' ),
					'size'  => 11,
				),
				'fill'      => array(
					'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
					'startColor' => array( 'rgb' => '0F172A' ),
				),
				'alignment' => array(
					'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
					'wrapText'   => false,
				),
			)
		);

		for ( $column = 1; $column <= $column_count; ++$column ) {
			$letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $column );
			$sheet->getColumnDimension( $letter )->setWidth( $columns[ $column - 1 ][1] );
		}

		$last_template_row = $template_rows + 1;
		$sheet->getStyle( 'A2:' . $last_column . $last_template_row )->applyFromArray(
			array(
				'borders' => array(
					'allBorders' => array(
						'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR,
						'color'       => array( 'rgb' => 'CBD5E1' ),
					),
				),
				'alignment' => array(
					'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
				),
			)
		);
		$sheet->getStyle( 'A2:' . $last_column . $last_template_row )->getNumberFormat()->setFormatCode( '@' );

		if ( 'units' === $type ) {
			$sheet->getStyle( 'C2:C' . $last_template_row )->getNumberFormat()->setFormatCode( '0' );
			self::add_floor_validation( $sheet );
		} elseif ( 'residents' === $type ) {
			$multi_property = count( $resident_properties ) > 1;
			$email_column   = $multi_property ? 'D' : 'C';
			$phone_column   = $multi_property ? 'E' : 'D';
			$type_column    = $multi_property ? 'G' : 'F';
			self::add_resident_input_validation( $sheet, SmartSused_Owner_Import::MAX_ROWS, $email_column, $phone_column );
			self::add_resident_type_validation( $sheet, SmartSused_Owner_Import::MAX_ROWS, $type_column );

			if ( $multi_property ) {
				self::add_resident_property_validation( $spreadsheet, $sheet, $resident_properties, SmartSused_Owner_Import::MAX_ROWS );
			} else {
				$unit_context = $this->get_property_context( (int) $resident_properties[0]['ID'] );
				if ( is_wp_error( $unit_context ) ) {
					$spreadsheet->disconnectWorksheets();
					return $unit_context;
				}

				if ( ! empty( $unit_context['units'] ) ) {
					self::add_resident_unit_validation( $spreadsheet, $sheet, $unit_context['units'], SmartSused_Owner_Import::MAX_ROWS, 'E' );
				}
			}
		}

		$filename = 'residents' === $type ? 'smartsused-obyvatelia.xlsx' : 'smartsused-' . $type . '-template.xlsx';
		$content  = self::write_workbook( $spreadsheet );
		$spreadsheet->disconnectWorksheets();

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$response = new WP_REST_Response( $content, 200 );
		$response->header( 'Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		$response->header( 'Content-Length', (string) strlen( $content ) );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * Stream the binary workbook body without REST JSON encoding.
	 *
	 * @param bool             $served  Whether the response has already been served.
	 * @param WP_HTTP_Response $result  Response object.
	 * @param WP_REST_Request  $request REST request.
	 * @param WP_REST_Server   $server  REST server.
	 * @return bool
	 */
	public function serve_xlsx_response( $served, $result, $request, $server ) {
		unset( $server );

		if (
			$served
			|| '/smartsused/v1/import/template' !== $request->get_route()
			|| ! ( $result instanceof WP_REST_Response )
			|| 200 !== $result->get_status()
			|| ! is_string( $result->get_data() )
		) {
			return $served;
		}

		$headers = $result->get_headers();

		header( 'Content-Type: ' . $headers['Content-Type'] );
		header( 'Content-Disposition: ' . $headers['Content-Disposition'] );
		header( 'Content-Length: ' . $headers['Content-Length'] );
		header( 'Cache-Control: ' . $headers['Cache-Control'] );
		header( 'X-Content-Type-Options: nosniff' );
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return true;
	}

	/**
	 * Load a bounded list of published properties for the resident template.
	 *
	 * @param int[] $property_ids Optional property scope; an empty list loads all.
	 * @return array|WP_Error
	 */
	private function get_resident_properties( $property_ids ) {
		global $wpdb;

		$where      = '';
		$parameters = array( 'smartsused_property', 'publish' );
		if ( ! empty( $property_ids ) ) {
			$property_ids = array_values( array_unique( array_filter( array_map( 'absint', $property_ids ) ) ) );
			$where        = ' AND ID IN (' . implode( ',', array_fill( 0, count( $property_ids ), '%d' ) ) . ')';
			$parameters   = array_merge( $parameters, $property_ids );
		}
		$parameters[] = self::MAX_PROPERTY_UNITS + 1;

		$properties = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = %s{$where}
				ORDER BY post_title ASC, ID ASC LIMIT %d",
				$parameters
			),
			ARRAY_A
		);

		if ( ! is_array( $properties ) ) {
			error_log( 'SmartSused Core: Resident template properties could not be loaded.' );
			return new WP_Error(
				'smartsused_template_properties_query_failed',
				__( 'Zoznam bytových domov pre šablónu sa nepodarilo načítať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( count( $properties ) > self::MAX_PROPERTY_UNITS ) {
			return new WP_Error(
				'smartsused_template_properties_limit',
				__( 'Zoznam bytových domov je príliš veľký na bezpečné vytvorenie šablóny.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		return $properties;
	}

	/**
	 * Load the published property and a bounded list of its published units.
	 *
	 * @param int $property_id Property ID.
	 * @return array|WP_Error Property post and unit rows, or an error.
	 */
	private function get_property_context( $property_id ) {
		global $wpdb;

		$property = get_post( $property_id );

		if (
			! ( $property instanceof WP_Post )
			|| 'smartsused_property' !== $property->post_type
			|| 'publish' !== $property->post_status
		) {
			return new WP_Error(
				'smartsused_template_property_not_found',
				__( 'Vybraný bytový dom neexistuje alebo nie je publikovaný.', 'smartsused-core' ),
				array( 'status' => 404 )
			);
		}

		$units = $wpdb->get_results(
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
				self::MAX_PROPERTY_UNITS + 1
			),
			ARRAY_A
		);

		if ( ! is_array( $units ) ) {
			error_log( 'SmartSused Core: Property units could not be loaded for the contextual import template.' );
			return new WP_Error(
				'smartsused_template_units_query_failed',
				__( 'Bytové jednotky pre zvolený dom sa nepodarilo načítať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( count( $units ) > self::MAX_PROPERTY_UNITS ) {
			return new WP_Error(
				'smartsused_template_units_limit',
				sprintf(
					/* translators: %d: maximum supported units in a contextual dropdown. */
					__( 'Dom obsahuje viac ako %d bytových jednotiek; kontextovú šablónu nemožno bezpečne vytvoriť.', 'smartsused-core' ),
					self::MAX_PROPERTY_UNITS
				),
				array( 'status' => 400 )
			);
		}

		foreach ( $units as &$unit ) {
			$unit['property_title'] = $property->post_title;
		}
		unset( $unit );

		return array(
			'property' => $property,
			'units'    => $units,
		);
	}

	/**
	 * Convert a supported unit title and entrance to the dropdown label.
	 *
	 * @param string $title Unit post title.
	 * @param string $entrance Entrance meta value.
	 * @return string
	 */
	public static function format_unit_option( $title, $entrance ) {
		$title    = trim( (string) $title );
		$entrance = trim( (string) $entrance );
		$number   = $title;

		if ( 1 === preg_match( '/^Vchod (.+), Byt (.+)$/u', $title, $matches ) ) {
			$entrance = trim( $matches[1] );
			$number   = trim( $matches[2] );
		} elseif ( 1 === preg_match( '/^Byt (.+)$/u', $title, $matches ) ) {
			$number = trim( $matches[1] );
		}

		if ( '' === $entrance ) {
			return sprintf(
				/* translators: %s: apartment number. */
				__( 'Byt %s', 'smartsused-core' ),
				$number
			);
		}

		return sprintf(
			/* translators: 1: entrance, 2: apartment number. */
			__( 'Vchod %1$s - Byt %2$s', 'smartsused-core' ),
			$entrance,
			$number
		);
	}

	/**
	 * Format unit options and disambiguate identical labels across properties.
	 *
	 * @param array $units Unit rows with ID, post_title, entrance, and property_title.
	 * @return array<int, array{label: string, id: int}>
	 */
	public static function format_resident_unit_options( $units ) {
		$base_labels = array();
		foreach ( $units as $unit ) {
			$base = self::format_unit_option( $unit['post_title'], $unit['entrance'] );
			$key  = strtolower( remove_accents( $base ) );
			$base_labels[ $key ] = isset( $base_labels[ $key ] ) ? $base_labels[ $key ] + 1 : 1;
		}

		$options = array();
		foreach ( $units as $unit ) {
			$base  = self::format_unit_option( $unit['post_title'], $unit['entrance'] );
			$label = $base;
			if ( isset( $base_labels[ strtolower( remove_accents( $base ) ) ] ) && $base_labels[ strtolower( remove_accents( $base ) ) ] > 1 && ! empty( $unit['property_title'] ) ) {
				$label = $unit['property_title'] . ' — ' . $base;
			}
			$options[] = array(
				'label' => $label,
				'id'    => (int) $unit['ID'],
			);
		}

		return $options;
	}

	/**
	 * Load a bounded list of published units across all published properties.
	 *
	 * @return array|WP_Error Unit rows or an error.
	 */
	private function get_all_resident_units() {
		global $wpdb;

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
				WHERE unit.post_type = %s AND unit.post_status = %s
				ORDER BY property.post_title ASC, unit.post_title ASC
				LIMIT %d",
				'_vchod',
				'smartsused_property',
				'publish',
				'smartsused_unit',
				'publish',
				self::MAX_PROPERTY_UNITS + 1
			),
			ARRAY_A
		);

		if ( ! is_array( $units ) ) {
			error_log( 'SmartSused Core: Resident unit options could not be loaded for the import template.' );
			return new WP_Error(
				'smartsused_template_units_query_failed',
				__( 'Zoznam bytových jednotiek pre šablónu sa nepodarilo načítať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( count( $units ) > self::MAX_PROPERTY_UNITS ) {
			return new WP_Error(
				'smartsused_template_units_limit',
				sprintf(
					/* translators: %d: maximum supported units in the dropdown. */
					__( 'Zoznam obsahuje viac ako %d bytových jednotiek; šablónu nemožno bezpečne vytvoriť.', 'smartsused-core' ),
					self::MAX_PROPERTY_UNITS
				),
				array( 'status' => 400 )
			);
		}

		return array( 'units' => $units );
	}

	/**
	 * Add a database-backed unit dropdown to the resident workbook.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet Workbook.
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Input worksheet.
	 * @param array $units Published units.
	 * @param int $template_rows Number of import rows.
	 */
	private static function add_resident_unit_validation( $spreadsheet, $sheet, $units, $template_rows, $column ) {
		$options = self::format_resident_unit_options( $units );
		if ( empty( $options ) ) {
			return;
		}

		$unit_options = $spreadsheet->createSheet();
		$unit_options->setTitle( '_ZoznamJednotiek' );
		$unit_options->setSheetState( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN );

		foreach ( $options as $index => $option ) {
			$unit_options->setCellValueExplicit(
				'A' . ( $index + 1 ),
				$option['label'],
				\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
			);
		}

		$spreadsheet->addNamedRange(
			new \PhpOffice\PhpSpreadsheet\NamedRange(
				'ResidentUnitOptions',
				$unit_options,
				'$A$1:$A$' . count( $options )
			)
		);
		self::add_list_validation(
			$sheet,
			$column . '2:' . $column . ( $template_rows + 1 ),
			'=ResidentUnitOptions',
			__( 'Číslo bytu', 'smartsused-core' ),
			__( 'Vyberte bytovú jednotku zo zoznamu.', 'smartsused-core' ),
			__( 'Vyberte byt zo zoznamu', 'smartsused-core' ),
			__( 'Použite hodnotu z rozbaľovacieho zoznamu.', 'smartsused-core' )
		);
		$spreadsheet->setActiveSheetIndex( 0 );
	}

	/**
	 * Add bounded whole-number validation for the floor column.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Worksheet.
	 */
	private static function add_floor_validation( $sheet ) {
		$validation = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
		$validation->setType( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_WHOLE );
		$validation->setErrorStyle( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP );
		$validation->setOperator( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::OPERATOR_BETWEEN );
		$validation->setFormula1( '-10' );
		$validation->setFormula2( '250' );
		$validation->setAllowBlank( true );
		$validation->setShowInputMessage( true );
		$validation->setPromptTitle( __( 'Poschodie', 'smartsused-core' ) );
		$validation->setPrompt( __( 'Zadajte celé číslo poschodia.', 'smartsused-core' ) );
		$validation->setShowErrorMessage( true );
		$validation->setErrorTitle( __( 'Neplatné poschodie', 'smartsused-core' ) );
		$validation->setError( __( 'Poschodie musí byť celé číslo od -10 do 250.', 'smartsused-core' ) );

		$validation->setSqref( 'C2:C' . ( SmartSused_Unit_Import::MAX_ROWS + 1 ) );
		$sheet->setDataValidation( 'C2', $validation );
	}

	/**
	 * Add the owner/tenant dropdown to all resident rows.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Worksheet.
	 */
	private static function add_resident_type_validation( $sheet, $template_rows, $column ) {
		$validation = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
		$validation->setType( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST );
		$validation->setErrorStyle( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP );
		$validation->setFormula1( '"Vlastník,Nájomca"' );
		$validation->setAllowBlank( false );
		$validation->setShowDropDown( false );
		$validation->setShowInputMessage( true );
		$validation->setPromptTitle( __( 'Typ rezidenta', 'smartsused-core' ) );
		$validation->setPrompt( __( 'Vyberte vlastníka alebo nájomcu z rozbaľovacieho zoznamu.', 'smartsused-core' ) );
		$validation->setShowErrorMessage( true );
		$validation->setErrorTitle( __( 'Neplatný typ rezidenta', 'smartsused-core' ) );
		$validation->setError( __( 'Vyberte hodnotu Vlastník alebo Nájomca.', 'smartsused-core' ) );

		$validation->setSqref( $column . '2:' . $column . ( $template_rows + 1 ) );
		$sheet->setDataValidation( $column . '2', $validation );
	}

	/**
	 * Add a dropdown with the manager's assigned property names.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet Workbook.
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Input worksheet.
	 * @param array $properties Published properties.
	 * @param int $template_rows Number of input rows.
	 */
	private static function add_resident_property_validation( $spreadsheet, $sheet, $properties, $template_rows ) {
		$property_options = $spreadsheet->createSheet();
		$property_options->setTitle( '_ZoznamDomov' );
		$property_options->setSheetState( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN );

		foreach ( $properties as $index => $property ) {
			$property_options->setCellValueExplicit(
				'A' . ( $index + 1 ),
				(string) $property['post_title'],
				\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
			);
		}

		$spreadsheet->addNamedRange(
			new \PhpOffice\PhpSpreadsheet\NamedRange(
				'ResidentPropertyOptions',
				$property_options,
				'$A$1:$A$' . count( $properties )
			)
		);
		self::add_list_validation(
			$sheet,
			'A2:A' . ( $template_rows + 1 ),
			'=ResidentPropertyOptions',
			__( 'Názov bytového domu', 'smartsused-core' ),
			__( 'Vyberte bytový dom priradený k vášmu účtu.', 'smartsused-core' ),
			__( 'Vyberte bytový dom zo zoznamu', 'smartsused-core' ),
			__( 'Použite hodnotu z rozbaľovacieho zoznamu.', 'smartsused-core' )
		);
		$spreadsheet->setActiveSheetIndex( 0 );
	}

	/**
	 * Add one range-based custom validation to avoid per-cell validation objects.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Worksheet.
	 * @param string $range Target cell range.
	 * @param string $formula Excel validation formula.
	 * @param string $title Prompt title.
	 * @param string $prompt Prompt text.
	 * @param string $error_title Error title.
	 * @param string $error Error text.
	 */
	private static function add_custom_validation( $sheet, $range, $formula, $title, $prompt, $error_title, $error ) {
		$validation = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
		$validation->setType( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_CUSTOM );
		$validation->setErrorStyle( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP );
		$validation->setFormula1( $formula );
		$validation->setAllowBlank( true );
		$validation->setShowInputMessage( true );
		$validation->setPromptTitle( $title );
		$validation->setPrompt( $prompt );
		$validation->setShowErrorMessage( true );
		$validation->setErrorTitle( $error_title );
		$validation->setError( $error );
		$validation->setSqref( $range );
		$cell = explode( ':', $range )[0];
		$sheet->setDataValidation( $cell, $validation );
	}

	/**
	 * Add a named-range list validation to a cell range.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Worksheet.
	 * @param string $range Target cell range.
	 * @param string $formula List formula.
	 * @param string $title Prompt title.
	 * @param string $prompt Prompt text.
	 * @param string $error_title Error title.
	 * @param string $error Error text.
	 */
	private static function add_list_validation( $sheet, $range, $formula, $title, $prompt, $error_title, $error ) {
		$validation = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
		$validation->setType( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST );
		$validation->setErrorStyle( \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP );
		$validation->setFormula1( $formula );
		$validation->setAllowBlank( true );
		$validation->setShowDropDown( false );
		$validation->setShowInputMessage( true );
		$validation->setPromptTitle( $title );
		$validation->setPrompt( $prompt );
		$validation->setShowErrorMessage( true );
		$validation->setErrorTitle( $error_title );
		$validation->setError( $error );
		$validation->setSqref( $range );
		$cell = explode( ':', $range )[0];
		$sheet->setDataValidation( $cell, $validation );
	}

	/**
	 * Add email and phone checks to the standard resident columns.
	 *
	 * Excel validation formulas do not support regular expressions. These
	 * bounded formulas check the same address and character constraints.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Worksheet.
	 * @param int $template_rows Number of input rows.
	 */
	private static function add_resident_input_validation( $sheet, $template_rows, $email_column, $phone_column ) {
		$last_row = $template_rows + 1;
		$email_cell = $email_column . '2';
		$phone_cell = $phone_column . '2';
		self::add_custom_validation(
			$sheet,
			$email_column . '2:' . $email_column . $last_row,
			'=OR(' . $email_cell . '="",IFERROR(AND(FIND("@",' . $email_cell . ')>1,FIND(".",' . $email_cell . ',FIND("@",' . $email_cell . ')+2)>FIND("@",' . $email_cell . ')+1,LEN(' . $email_cell . ')-LEN(SUBSTITUTE(' . $email_cell . ',"@",""))=1,ISERROR(FIND(" ",' . $email_cell . '))),FALSE))',
			__( 'E-mail', 'smartsused-core' ),
			__( 'Zadajte platnú e-mailovú adresu v tvare meno@domena.sk.', 'smartsused-core' ),
			__( 'Neplatný e-mail', 'smartsused-core' ),
			__( 'E-mail musí obsahovať jednu zavináč a názov domény s bodkou.', 'smartsused-core' )
		);

		self::add_custom_validation(
			$sheet,
			$phone_column . '2:' . $phone_column . $last_row,
			'=OR(' . $phone_cell . '="",AND(LEN(' . $phone_cell . ')>=7,LEN(' . $phone_cell . ')<=20,LEN(' . $phone_cell . ')-LEN(SUBSTITUTE(' . $phone_cell . ',"+",""))<=1,IFERROR(FIND("+",' . $phone_cell . ')=1,TRUE),SUMPRODUCT(--ISNUMBER(FIND(MID(' . $phone_cell . ',ROW(INDIRECT("1:"&LEN(' . $phone_cell . '))),1),"0123456789+")))=LEN(' . $phone_cell . ')))',
			__( 'Telefón', 'smartsused-core' ),
			__( 'Zadajte telefónne číslo s dĺžkou 7 až 20 znakov. Povolené sú číslice a jedno úvodné plus.', 'smartsused-core' ),
			__( 'Neplatný telefón', 'smartsused-core' ),
			__( 'Telefón môže obsahovať iba číslice a najviac jedno plus na začiatku.', 'smartsused-core' )
		);
	}

	/**
	 * Serialize the workbook to a memory buffer.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet Workbook.
	 * @return string|WP_Error Binary XLSX content or an explicit error.
	 */
	private static function write_workbook( $spreadsheet ) {
		$buffer_level = ob_get_level();
		ob_start();

		try {
			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
			$writer->save( 'php://output' );
			$content = ob_get_contents();
			ob_end_clean();

			return is_string( $content ) && '' !== $content
				? $content
				: new WP_Error(
					'smartsused_template_generation_failed',
					__( 'Excel šablónu sa nepodarilo vygenerovať.', 'smartsused-core' ),
					array( 'status' => 500 )
				);
		} catch ( \Throwable $exception ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			error_log( 'SmartSused Core: XLSX template generation failed: ' . $exception->getMessage() );

			return new WP_Error(
				'smartsused_template_generation_failed',
				__( 'Excel šablónu sa nepodarilo vygenerovať.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Return a consistent access-denied error.
	 *
	 * @return WP_Error
	 */
	private static function forbidden_error() {
		return new WP_Error(
			'smartsused_template_access_denied',
			__( 'Na stiahnutie Excel šablóny musíte byť prihlásený ako správca SmartSused.', 'smartsused-core' ),
			array( 'status' => 403 )
		);
	}
}
