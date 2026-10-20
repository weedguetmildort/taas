<?php
/**
 * Constructor-time sanity checks for row values.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Constructor-time sanity checks for row values.
 */
final class TAAS_Guard {

	/**
	 * Check that a UFID is 1-8 characters.
	 *
	 * @param string $field Column name, used in the error message.
	 * @param string $value UFID to check.
	 * @throws InvalidArgumentException If the UFID is empty or longer than 8 characters.
	 */
	public static function ufid( string $field, string $value ): void {
		if ( '' === $value || strlen( $value ) > 8 ) {
			throw new InvalidArgumentException( sprintf( '%s must be 1-8 characters.', esc_html( $field ) ) );
		}
	}

	/**
	 * Check that a country code is two letters, or null.
	 *
	 * @param string|null $value ISO 3166-1 alpha-2 code, or null.
	 * @throws InvalidArgumentException If the value is not exactly two letters.
	 */
	public static function country_code( ?string $value ): void {
		if ( null !== $value && 1 !== preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
			throw new InvalidArgumentException( 'country_code must be a two-letter ISO 3166-1 alpha-2 code.' );
		}
	}

	/**
	 * Check that a GPA is within the range decimal(3,2) can hold, or null.
	 *
	 * @param float|null $value GPA, or null. Bounds match decimal(3,2).
	 * @throws InvalidArgumentException If the GPA is outside 0.00-4.00.
	 */
	public static function gpa( ?float $value ): void {
		if ( null !== $value && ( $value < 0.0 || $value > 4.00 ) ) {
			throw new InvalidArgumentException( 'gpa must be between 0.00 and 4.00.' );
		}
	}

	/**
	 * Check that a preference rank is 1 or greater, or null.
	 *
	 * @param string   $field Column name, used in the error message.
	 * @param int|null $value Preference rank, or null.
	 * @throws InvalidArgumentException If the rank is below 1.
	 */
	public static function rank( string $field, ?int $value ): void {
		if ( null !== $value && $value < 1 ) {
			throw new InvalidArgumentException( sprintf( '%s must be 1 or greater.', esc_html( $field ) ) );
		}
	}
}
