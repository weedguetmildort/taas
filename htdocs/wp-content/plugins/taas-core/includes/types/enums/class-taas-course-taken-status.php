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
	case Taking          = 2;
	case Not_Taken       = 3;
	case Not_Applicable  = 4;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Taken_At_UF     => 'Taken',
			self::Taking          => 'Currently Enrolled',
			self::Not_Taken       => 'Not Yet Taken',
			self::Not_Applicable  => 'N/A',
		};
	}
}
