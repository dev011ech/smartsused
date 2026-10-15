<?php
/**
 * Validation helpers for SmartSused REST API input.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates Slovak and Czech contact and payment details.
 */
final class SmartSused_Validation {

	/**
	 * Check supported Slovak mobile and Czech phone formats.
	 *
	 * Accepted examples: +421912345678, +421 912 345 678, +421912 345 678,
	 * 0912345678, +420123456789, and +420 123 456 789.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	public static function is_valid_phone( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		return 1 === preg_match(
			'/^(?:09\d{8}|\+4219\d{2} \d{3} \d{3}|\+4219\d{8}|\+421 9\d{2} \d{3} \d{3}|\+420\d{9}|\+420 \d{3} \d{3} \d{3})$/',
			$value
		);
	}

	/**
	 * Check a Slovak postcode with or without the standard space.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	public static function is_valid_slovak_postcode( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^\d{3} ?\d{2}$/', $value );
	}

	/**
	 * Check Slovak IBAN structure and its ISO 13616 MOD-97 checksum.
	 *
	 * Accepts the canonical 24-character IBAN and the usual spaced form,
	 * for example SK3112000000198742637541 or SK31 1200 0000 1987 4263 7541.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	public static function is_valid_slovak_iban( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:SK\d{22}|SK\d{2}(?: \d{4}){5})$/i', $value ) ) {
			return false;
		}

		$iban     = str_replace( ' ', '', strtoupper( $value ) );
		$reordered = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$numeric   = '';

		for ( $index = 0, $length = strlen( $reordered ); $index < $length; $index++ ) {
			$character = $reordered[ $index ];

			if ( ctype_alpha( $character ) ) {
				$numeric .= (string) ( ord( $character ) - 55 );
			} else {
				$numeric .= $character;
			}
		}

		$remainder = 0;

		for ( $index = 0, $length = strlen( $numeric ); $index < $length; $index++ ) {
			$remainder = ( ( $remainder * 10 ) + (int) $numeric[ $index ] ) % 97;
		}

		return 1 === $remainder;
	}

	/**
	 * REST validate_callback for phone fields.
	 *
	 * @param mixed           $value   Submitted value.
	 * @param WP_REST_Request $request REST request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public static function validate_phone( $value, $request, $param ) {
		if ( self::is_valid_phone( $value ) ) {
			return true;
		}

		return self::invalid_field(
			$param,
			__( 'Telefónne číslo musí byť v platnom slovenskom alebo českom formáte.', 'smartsused-core' )
		);
	}

	/**
	 * REST validate_callback for Slovak postcode fields.
	 *
	 * @param mixed           $value   Submitted value.
	 * @param WP_REST_Request $request REST request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public static function validate_slovak_postcode( $value, $request, $param ) {
		if ( self::is_valid_slovak_postcode( $value ) ) {
			return true;
		}

		return self::invalid_field(
			$param,
			__( 'PSČ musí obsahovať päť číslic, napríklad 974 01.', 'smartsused-core' )
		);
	}

	/**
	 * REST validate_callback for Slovak IBAN fields.
	 *
	 * @param mixed           $value   Submitted value.
	 * @param WP_REST_Request $request REST request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public static function validate_slovak_iban( $value, $request, $param ) {
		if ( self::is_valid_slovak_iban( $value ) ) {
			return true;
		}

		return self::invalid_field(
			$param,
			__( 'Slovenský IBAN je neplatný alebo má nesprávny kontrolný súčet.', 'smartsused-core' )
		);
	}

	/**
	 * Build a structured REST API validation error.
	 *
	 * @param string $field   REST request parameter.
	 * @param string $message Localized error message.
	 * @return WP_Error
	 */
	private static function invalid_field( $field, $message ) {
		return new WP_Error(
			'smartsused_invalid_field',
			$message,
			array(
				'status' => 400,
				'field'  => sanitize_key( $field ),
			)
		);
	}
}
