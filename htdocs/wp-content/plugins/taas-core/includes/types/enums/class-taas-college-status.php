<?php
/**
 * Enum for student_profiles.college_status.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Values for student_profiles.college_status.
 */
enum TAAS_College_Status: int {
	case Undergrad = 1;
	case Masters   = 2;
	case Phd       = 3;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Undergrad => 'Undergraduate',
			self::Masters   => 'Master\'s',
			self::Phd       => 'PhD',
		};
	}
}
