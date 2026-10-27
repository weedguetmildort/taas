<?php
/**
 * Enum for courses.course_semester.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Values for courses.course_semester.
 */
enum TAAS_Semester: int {
	case Fall     = 1;
	case Spring   = 2;
	case Summer_A = 3;
	case Summer_B = 4;
	case Summer_C = 5;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Fall     => 'Fall',
			self::Spring   => 'Spring',
			self::Summer_A => 'Summer A',
			self::Summer_B => 'Summer B',
			self::Summer_C => 'Summer C',
		};
	}

	/**
	 * Match free text such as "Fall", "summer a" or "SummerB" to a case.
	 *
	 * Case, spaces, underscores and hyphens are ignored.
	 *
	 * @param string $text Semester name typed by a user.
	 * @return self|null The matching case, or null if nothing matches.
	 */
	public static function try_from_label( string $text ): ?self {
		$wanted = strtolower( (string) preg_replace( '/[\s_-]+/', '', $text ) );

		foreach ( self::cases() as $case ) {
			if ( strtolower( str_replace( ' ', '', $case->label() ) ) === $wanted ) {
				return $case;
			}
		}

		return null;
	}
}
