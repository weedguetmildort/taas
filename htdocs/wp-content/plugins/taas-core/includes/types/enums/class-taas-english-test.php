<?php
/**
 * Enum for student_profiles English proficiency tests.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Values for student_profiles.english_test.
 */
enum TAAS_English_Test: int {
	case Speak     = 1;
	case Toefl_Ibt = 2;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Speak     => 'SPEAK',
			self::Toefl_Ibt => 'TOEFL iBT',
		};
	}
}
