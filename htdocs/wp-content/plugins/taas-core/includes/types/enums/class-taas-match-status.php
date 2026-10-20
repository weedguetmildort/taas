<?php
/**
 * Enum for applications.match_status.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Values for applications.match_status.
 */
enum TAAS_Match_Status: int {
	case Pending   = 1;
	case Matched   = 2;
	case Rejected  = 3;
	case Withdrawn = 4;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending   => 'Pending',
			self::Matched   => 'Matched',
			self::Rejected  => 'Rejected',
			self::Withdrawn => 'Withdrawn',
		};
	}
}
