<?php
/**
 * Enum for student_profiles.english_test_score.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Score ranges for SPEAK and TOEFL iBT.
 */
enum TAAS_English_Test_Score: int {
	case Below_Range    = 1;
	case Middle_Range   = 2;
	case Upper_Range    = 3;
	case Not_Applicable = 4;

	/**
	 * Human-readable score range for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Below_Range    => 'Under 45 SPEAK / Under 23 TOEFL iBT',
			self::Middle_Range   => '45–54 SPEAK / 23–27 TOEFL iBT',
			self::Upper_Range    => '55–60 SPEAK / 28–30 TOEFL iBT',
			self::Not_Applicable => 'N/A',
		};
	}
}
