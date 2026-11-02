<?php
/**
 * Enum for the stages of an application cycle.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * The stages of an application cycle, in order.
 */
enum TAAS_Cycle_Stage: int {
	use TAAS_Label_Lookup;

	case Student_Application = 1;
	case Hr_Review           = 2;
	case Professor_Ranking   = 3;
	case Admin_Matching      = 4;

	/**
	 * Name the API uses for this stage.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Student_Application => 'student_application',
			self::Hr_Review           => 'hr_review',
			self::Professor_Ranking   => 'professor_ranking',
			self::Admin_Matching      => 'admin_matching',
		};
	}
}
