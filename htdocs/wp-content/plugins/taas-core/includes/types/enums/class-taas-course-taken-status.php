<?php
/**
 * Enum for student_profiles EAP course statuses.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Values for student_profiles.eap5836_status and eap5837_status.
 */
enum TAAS_Course_Taken_Status: int {
	case Taken_At_UF     = 1;
	case Taken_Elsewhere = 2;
	case Taking          = 3;
	case Not_Taken       = 4;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Taken_At_UF     => 'Taken at UF',
			self::Taken_Elsewhere => 'Taken elsewhere',
			self::Taking          => 'Currently taking',
			self::Not_Taken       => 'Not taken',
		};
	}
}
