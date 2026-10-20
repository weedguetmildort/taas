<?php
/**
 * Casting helpers for raw database values.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Convert the strings $wpdb returns into native PHP types.
 */
final class TAAS_Cast {

	/**
	 * Cast a raw column value to int.
	 *
	 * @param mixed $value Raw column value.
	 * @return int
	 */
	public static function int( mixed $value ): int {
		return (int) $value;
	}

	/**
	 * Cast a raw column value to int, keeping NULL as null.
	 *
	 * @param mixed $value Raw column value.
	 * @return int|null
	 */
	public static function int_or_null( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Cast a raw column value to float, keeping NULL as null.
	 *
	 * @param mixed $value Raw column value.
	 * @return float|null
	 */
	public static function float_or_null( mixed $value ): ?float {
		return null === $value ? null : (float) $value;
	}

	/**
	 * Cast a raw 0/1 column value to bool.
	 *
	 * @param mixed $value Raw column value.
	 * @return bool
	 */
	public static function bool( mixed $value ): bool {
		return (bool) (int) $value;
	}

	/**
	 * Cast a raw column value to string, keeping NULL as null.
	 *
	 * @param mixed $value Raw column value.
	 * @return string|null
	 */
	public static function string_or_null( mixed $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	/**
	 * Parse a Y-m-d column value, or return null if it is empty or not a real date.
	 *
	 * @param mixed $value Raw column value.
	 * @return DateTimeImmutable|null
	 */
	public static function date_or_null( mixed $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );
		$errors = DateTimeImmutable::getLastErrors();

		// Reject impossible dates (2024-02-31, 0000-00-00) that PHP would otherwise roll over.
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date;
	}

	/**
	 * Convert a raw integer column into a backed enum case, or null for NULL.
	 *
	 * @param string $enum_class Backed enum class name, e.g. TAAS_Match_Status::class.
	 * @param mixed  $value      Raw column value.
	 * @return BackedEnum|null
	 * @throws UnexpectedValueException If the value is not a case of the enum.
	 */
	public static function enum_or_null( string $enum_class, mixed $value ): ?BackedEnum {
		if ( null === $value ) {
			return null;
		}

		$case = $enum_class::tryFrom( (int) $value );

		if ( null === $case ) {
			throw new UnexpectedValueException( sprintf( 'Invalid %s value: %s.', esc_html( $enum_class ), esc_html( (string) $value ) ) );
		}

		return $case;
	}

	/**
	 * Return a row as an array, whether $wpdb gave us an object or an array.
	 *
	 * @param array|object $row Database row.
	 * @return array<string, mixed>
	 */
	public static function row( array|object $row ): array {
		return (array) $row;
	}
}
