<?php
/**
 * Reads bounded CSV and XLSX files uploaded for SmartSused imports.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restricts XLSX parsing to the supported row and column range.
 */
final class SmartSused_Import_Read_Filter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {

	/**
	 * Highest row and column to load.
	 *
	 * @var int
	 */
	private $max_row;
	private $max_column;

	/**
	 * @param int $max_row    Highest row to load.
	 * @param int $max_column Highest column index to load.
	 */
	public function __construct( $max_row, $max_column ) {
		$this->max_row    = (int) $max_row;
		$this->max_column = (int) $max_column;
	}

	/**
	 * Check whether a cell is inside the permitted import range.
	 *
	 * @param string $column_address Cell column.
	 * @param int    $row             Cell row.
	 * @param string $worksheet_name  Worksheet name.
	 * @return bool
	 */
	public function readCell( $column_address, $row, $worksheet_name = '' ) {
		unset( $worksheet_name );

		return (int) $row <= $this->max_row
			&& \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $column_address ) <= $this->max_column;
	}
}

/**
 * Provides common file validation, CSV/XLSX parsing, and header normalization.
 */
final class SmartSused_Import_File {

	/**
	 * Maximum uncompressed XLSX size accepted from an upload.
	 */
	const MAX_XLSX_UNCOMPRESSED_SIZE = 33554432;

	/**
	 * Read an uploaded CSV or XLSX file within the import limits.
	 *
	 * @param array $upload    WordPress upload entry.
	 * @param int   $max_size  Maximum compressed upload size.
	 * @param int   $max_rows  Maximum number of data rows.
	 * @param int   $max_columns Maximum number of columns.
	 * @return array|WP_Error Header and numbered data rows, or an error.
	 */
	public static function read_rows( $upload, $max_size, $max_rows, $max_columns ) {
		if ( ! function_exists( 'finfo_open' ) ) {
			return new WP_Error(
				'smartsused_import_fileinfo_missing',
				__( 'Kontrola typu nahraného súboru nie je dostupná. Aktivujte PHP rozšírenie Fileinfo.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		if ( ! self::is_valid_upload( $upload, $max_size ) ) {
			return new WP_Error(
				'smartsused_import_file_invalid',
				__( 'Nahrajte platný CSV alebo Excel súbor s príponou .csv alebo .xlsx do veľkosti 5 MiB.', 'smartsused-core' ),
				array( 'status' => 400 )
			);
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $upload['name'] ), PATHINFO_EXTENSION ) );

		if ( 'csv' === $extension ) {
			return self::read_csv( $upload['tmp_name'], $max_rows );
		}

		return self::read_xlsx( $upload['tmp_name'], $max_rows, $max_columns );
	}

	/**
	 * Normalize localized and legacy header labels to import keys.
	 *
	 * @param array $headers Raw header row.
	 * @return array
	 */
	public static function normalize_headers( $headers ) {
		$aliases = array(
			'adresa_bytoveho_domu' => 'adresa',
			'nazov_bytoveho_domu'  => 'nazov_domu',
			'e_mail'               => 'email',
			'typ_rezidenta'        => 'typ_rezidenta',
		);

		$normalized = array();

		foreach ( $headers as $header ) {
			$value = is_string( $header ) ? trim( $header ) : '';
			$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
			$value = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
			$value = strtolower( $value );
			$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
			$value = trim( $value, '_' );

			$normalized[] = isset( $aliases[ $value ] ) ? $aliases[ $value ] : $value;
		}

		return $normalized;
	}

	/**
	 * Validate the uploaded file's origin, size, extension, and MIME type.
	 *
	 * @param array $upload   WordPress upload entry.
	 * @param int   $max_size Maximum upload size.
	 * @return bool
	 */
	private static function is_valid_upload( $upload, $max_size ) {
		if (
			! is_array( $upload )
			|| ! isset( $upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'] )
			|| UPLOAD_ERR_OK !== (int) $upload['error']
			|| ! is_string( $upload['tmp_name'] )
			|| ! is_uploaded_file( $upload['tmp_name'] )
			|| ! is_string( $upload['name'] )
			|| ! is_numeric( $upload['size'] )
			|| (int) $upload['size'] < 1
			|| (int) $upload['size'] > (int) $max_size
			|| ! is_readable( $upload['tmp_name'] )
			|| (int) filesize( $upload['tmp_name'] ) !== (int) $upload['size']
		) {
			return false;
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $upload['name'] ), PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
			return false;
		}

		$file_info = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $file_info ) {
			return false;
		}

		$mime_type = finfo_file( $file_info, $upload['tmp_name'] );
		finfo_close( $file_info );

		if ( 'csv' === $extension ) {
			return is_string( $mime_type )
				&& in_array( $mime_type, array( 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel' ), true );
		}

		return is_string( $mime_type )
			&& in_array(
				$mime_type,
				array(
					'application/zip',
					'application/x-zip',
					'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				),
				true
			);
	}

	/**
	 * Read a semicolon-delimited CSV file.
	 *
	 * @param string $path     Uploaded file path.
	 * @param int    $max_rows Maximum number of data rows.
	 * @return array|WP_Error
	 */
	private static function read_csv( $path, $max_rows ) {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return self::unreadable_error();
		}

		$header = fgetcsv( $handle, 0, ';', '"', '\\' );

		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return self::invalid_content_error();
		}

		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$rows      = array();
		$row_number = 1;

		while ( false !== ( $row = fgetcsv( $handle, 0, ';', '"', '\\' ) ) ) {
			++$row_number;

			if ( array( null ) === $row ) {
				continue;
			}

			if ( count( $rows ) >= (int) $max_rows ) {
				fclose( $handle );
				return self::too_many_rows_error( $max_rows );
			}

			$rows[] = array(
				'number' => $row_number,
				'values' => $row,
			);
		}

		if ( ! feof( $handle ) ) {
			fclose( $handle );
			return self::unreadable_error();
		}

		fclose( $handle );

		return array(
			'header' => $header,
			'rows'   => $rows,
		);
	}

	/**
	 * Read a single-visible-sheet XLSX workbook with bounded decompressed size.
	 *
	 * @param string $path       Uploaded file path.
	 * @param int    $max_rows   Maximum number of data rows.
	 * @param int    $max_columns Maximum number of columns.
	 * @return array|WP_Error
	 */
	private static function read_xlsx( $path, $max_rows, $max_columns ) {
		if (
			! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' )
			|| ! class_exists( 'ZipArchive' )
			|| ! extension_loaded( 'gd' )
		) {
			return new WP_Error(
				'smartsused_xlsx_dependency_missing',
				__( 'Čítanie súborov Excel nie je dostupné. Skontrolujte PhpSpreadsheet a PHP rozšírenia ZIP a GD.', 'smartsused-core' ),
				array( 'status' => 500 )
			);
		}

		$archive = new ZipArchive();
		$opened  = $archive->open( $path, ZipArchive::RDONLY );

		if ( true !== $opened ) {
			return self::invalid_content_error();
		}

		$uncompressed_size = 0;
		$has_workbook      = false;
		$has_content_types = false;

		for ( $index = 0; $index < $archive->numFiles; ++$index ) {
			$entry = $archive->statIndex( $index );

			if ( ! is_array( $entry ) || ! isset( $entry['name'], $entry['size'] ) ) {
				$archive->close();
				return self::invalid_content_error();
			}

			$uncompressed_size += (int) $entry['size'];
			$has_workbook       = $has_workbook || 'xl/workbook.xml' === $entry['name'];
			$has_content_types  = $has_content_types || '[Content_Types].xml' === $entry['name'];

			if ( $uncompressed_size > self::MAX_XLSX_UNCOMPRESSED_SIZE || $archive->numFiles > 100 ) {
				$archive->close();
				return new WP_Error(
					'smartsused_xlsx_uncompressed_limit',
					__( 'Excel súbor obsahuje priveľa dát na bezpečné spracovanie.', 'smartsused-core' ),
					array( 'status' => 400 )
				);
			}
		}

		$archive->close();

		if ( ! $has_workbook || ! $has_content_types ) {
			return self::invalid_content_error();
		}

		try {
			$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader( 'Xlsx' );
			$reader->setReadDataOnly( true );
			$reader->setReadEmptyCells( false );
			$reader->setReadFilter( new SmartSused_Import_Read_Filter( (int) $max_rows + 2, (int) $max_columns + 1 ) );
			$spreadsheet = $reader->load( $path );

			$visible_sheets = array_filter(
				$spreadsheet->getAllSheets(),
				static function ( $worksheet ) {
					return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE === $worksheet->getSheetState();
				}
			);

			if ( 1 !== count( $visible_sheets ) ) {
				$spreadsheet->disconnectWorksheets();
				return new WP_Error(
					'smartsused_xlsx_multiple_sheets',
					__( 'Excel súbor musí obsahovať práve jeden viditeľný pracovný hárok.', 'smartsused-core' ),
					array( 'status' => 400 )
				);
			}

			$sheet = reset( $visible_sheets );
			$highest_row = $sheet->getHighestDataRow();
			$highest_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $sheet->getHighestDataColumn() );

			if ( $highest_row > (int) $max_rows + 1 ) {
				$spreadsheet->disconnectWorksheets();
				return self::too_many_rows_error( $max_rows );
			}

			if ( $highest_column > (int) $max_columns ) {
				$spreadsheet->disconnectWorksheets();
				return new WP_Error(
					'smartsused_xlsx_too_many_columns',
					__( 'Excel súbor obsahuje viac stĺpcov, ako povoľuje zvolený import.', 'smartsused-core' ),
					array( 'status' => 400 )
				);
			}

			if ( $highest_row < 1 ) {
				$spreadsheet->disconnectWorksheets();
				return self::invalid_content_error();
			}

			$last_column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( (int) $max_columns );
			$values      = $sheet->rangeToArray(
				'A1:' . $last_column . $highest_row,
				null,
				false,
				false,
				false
			);
			$spreadsheet->disconnectWorksheets();

			if ( empty( $values ) || ! is_array( $values[0] ) ) {
				return self::invalid_content_error();
			}

			$header = self::trim_trailing_empty_cells( array_slice( $values[0], 0, (int) $max_columns ) );
			$rows   = array();

			foreach ( array_slice( $values, 1, null, true ) as $index => $row ) {
				if ( self::is_empty_row( $row ) ) {
					continue;
				}

				$row_values = self::trim_trailing_empty_cells( array_slice( $row, 0, (int) $max_columns ) );

				$rows[] = array(
					'number' => (int) $index + 1,
					'values' => array_map(
						static function ( $value ) {
							return is_scalar( $value ) ? (string) $value : '';
						},
						$row_values
					),
				);
			}

			return array(
				'header' => $header,
				'rows'   => $rows,
			);
		} catch ( \Throwable $exception ) {
			error_log( 'SmartSused Core: Excel import parsing failed: ' . $exception->getMessage() );
			return self::invalid_content_error();
		}
	}

	/**
	 * Determine whether a spreadsheet row is empty.
	 *
	 * @param array $row Row values.
	 * @return bool
	 */
	private static function is_empty_row( $row ) {
		foreach ( $row as $value ) {
			if ( null !== $value && '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove padding cells added by the bounded XLSX read range.
	 *
	 * @param array $values Cell values.
	 * @return array
	 */
	private static function trim_trailing_empty_cells( $values ) {
		while ( ! empty( $values ) && ( null === end( $values ) || '' === end( $values ) ) ) {
			array_pop( $values );
		}

		return $values;
	}

	/**
	 * Return a bounded error for unreadable uploads.
	 *
	 * @return WP_Error
	 */
	private static function unreadable_error() {
		return new WP_Error(
			'smartsused_import_file_unreadable',
			__( 'Nahraný súbor sa nepodarilo prečítať.', 'smartsused-core' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Return an error for malformed or unsupported workbooks.
	 *
	 * @return WP_Error
	 */
	private static function invalid_content_error() {
		return new WP_Error(
			'smartsused_import_file_invalid_content',
			__( 'Súbor je poškodený alebo nemá podporovaný formát CSV/Excel.', 'smartsused-core' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Return an error when a file exceeds the import row limit.
	 *
	 * @param int $max_rows Maximum data rows.
	 * @return WP_Error
	 */
	private static function too_many_rows_error( $max_rows ) {
		return new WP_Error(
			'smartsused_import_file_too_many_rows',
			sprintf(
				/* translators: %d: maximum supported number of data rows. */
				__( 'Súbor prekročil maximálny počet %d dátových riadkov.', 'smartsused-core' ),
				(int) $max_rows
			),
			array( 'status' => 400 )
		);
	}
}
