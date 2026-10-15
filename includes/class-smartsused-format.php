<?php
/**
 * Slovak formatting helpers for SmartSused Core.
 *
 * @package SmartSused_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Formats currency, MySQL dates, and utility meter readings for Slovakia.
 */
final class SmartSused_Format {

	/**
	 * Format a monetary amount in euros.
	 *
	 * @param int|float|string $value Amount to format.
	 * @return string
	 * @throws InvalidArgumentException If the amount is not a finite number.
	 */
	public static function currency( $value ) {
		$number = self::validate_number( $value );

		return number_format( $number, 2, ',', "\u{00A0}" ) . "\u{00A0}€";
	}

	/**
	 * Format a MySQL date or datetime without changing its wall-clock time.
	 *
	 * Accepted formats are Y-m-d and Y-m-d H:i:s.
	 *
	 * @param string $value      MySQL date or datetime.
	 * @param bool   $with_time  Whether to include the time for datetime values.
	 * @return string
	 * @throws InvalidArgumentException If the input is not a valid MySQL date.
	 */
	public static function date( $value, $with_time = false ) {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Date must be a MySQL date or datetime string.' );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$format = '!Y-m-d';
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			$format = '!Y-m-d H:i:s';
		} else {
			throw new InvalidArgumentException( 'Date must use Y-m-d or Y-m-d H:i:s format.' );
		}

		$date   = DateTimeImmutable::createFromFormat( $format, $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();

		if (
			false === $date
			|| ( false !== $errors && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) )
		) {
			throw new InvalidArgumentException( 'Date is invalid.' );
		}

		$output = $date->format( 'j. n. Y' );

		if ( $with_time && 'Y-m-d H:i:s' === substr( $format, 1 ) ) {
			$output .= ' o ' . $date->format( 'H:i' );
		}

		return $output;
	}

	/**
	 * Format a water or heat meter reading to three decimal places.
	 *
	 * @param int|float|string $value Reading to format.
	 * @param string           $unit  Supported unit: m³ or kWh.
	 * @return string
	 * @throws InvalidArgumentException If the value or unit is invalid.
	 */
	public static function meter( $value, $unit ) {
		if ( ! in_array( $unit, array( 'm³', 'kWh' ), true ) ) {
			throw new InvalidArgumentException( 'Unit must be m³ or kWh.' );
		}

		$number = self::validate_number( $value );

		return number_format( $number, 3, ',', "\u{00A0}" ) . "\u{00A0}" . $unit;
	}

	/**
	 * Convert a numeric input to a finite float.
	 *
	 * @param int|float|string $value Numeric input.
	 * @return float
	 * @throws InvalidArgumentException If the input is not a finite number.
	 */
	private static function validate_number( $value ) {
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
			throw new InvalidArgumentException( 'Value must be a finite number.' );
		}

		return (float) $value;
	}
}
