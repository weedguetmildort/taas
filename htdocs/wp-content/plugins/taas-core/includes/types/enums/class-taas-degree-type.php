<?php
/**
 * Enum for academic_background.degree_type.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Values for academic_background.degree_type.
 */
enum TAAS_Degree_Type: int {
	case AA  = 1;
	case BS  = 2;
	case MS  = 3;
	case Phd = 4;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::AA  => 'AA',
			self::BS  => 'BS',
			self::MS  => 'MS',
			self::Phd => 'PhD',
		};
	}
}
