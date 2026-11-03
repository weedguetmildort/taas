<?php
/**
 * The student-facing view of the current application cycle.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * No implicit term or open period: the administrator sets both through the application cycle.
 */
final class TAAS_Application_Period {

	/**
	 * Read the cycle's term and whether its student_application stage is active.
	 *
	 * The cycle is the only source of truth. is_open agrees with TAAS_Stage_Gate,
	 * so the student pages and the API close at the same moment. No grace
	 * behavior is implemented.
	 *
	 * @return array{
	 *   semester: int,
	 *   year: int,
	 *   label: string,
	 *   is_open: bool,
	 *   closes_at: int|null
	 * }|WP_Error The period, or a 503 error when no cycle has been set.
	 *            closes_at is student_application's end_at as a UTC Unix timestamp; null means no deadline.
	 * @throws RuntimeException If the cycle query fails.
	 */
	public static function current(): array|WP_Error {
		$cycle = ( new TAAS_Application_Cycle_Repository() )->current();
		if ( null === $cycle ) {
			return new WP_Error( 'taas_period_unconfigured', 'The application period has not been configured. Please contact the administrator.', array( 'status' => 503 ) );
		}
		$deadline = $cycle->stage_dates[ TAAS_Cycle_Stage::Student_Application->label() ]['end_at'] ?? null;
		return array(
			'semester'  => $cycle->cycle_semester->value,
			'year'      => $cycle->cycle_year,
			'label'     => $cycle->cycle_semester->label() . ' ' . $cycle->cycle_year,
			'is_open'   => TAAS_Cycle_Stage::Student_Application === $cycle->active_stage(),
			'closes_at' => $deadline?->getTimestamp(),
		);
	}
}
