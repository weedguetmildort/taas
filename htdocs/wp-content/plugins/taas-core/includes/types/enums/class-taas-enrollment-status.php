<?php
/**
 * Enum for student_profiles.enrollment_status.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Values for student_profiles.enrollment_status: the program the student is
 * currently in. Kept separate from TAAS_Degree_Type, which describes degrees in
 * academic_background and has no "not enrolled" value.
 */
enum TAAS_Enrollment_Status: int {
	case Not_Enrolled  = 0;
	case Undergraduate = 1;
	case Masters       = 2;
	case Phd           = 3;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Not_Enrolled  => 'Not enrolled',
			self::Undergraduate => 'Undergraduate',
			self::Masters       => 'Masters',
			self::Phd           => 'PhD',
		};
	}
}
