<?php
/**
 * Application-cycle fixture shared by the student integration checks.
 *
 * @package TAAS_Tests
 */

/**
 * Point the current application cycle at a term, with applications open or closed.
 *
 * Takes the shape TAAS_Application_Period reports, so a check can change one
 * field and save again. An empty array removes the cycle. This writes the real
 * row: call it inside the check's transaction, or restore the cycle afterwards.
 *
 * @param array $period semester, year, is_open and an optional closes_at.
 */
function taas_test_set_period( array $period ): void {
	$cycles = new TAAS_Application_Cycle_Repository();
	if ( ! $period ) {
		$cycles->delete( TAAS_Application_Cycle::CURRENT_ID );
		return;
	}
	$now = time();
	$end = $period['is_open'] ? ( $period['closes_at'] ?? null ) : $now - 60;
	$cycles->save(
		new TAAS_Application_Cycle(
			TAAS_Semester::from( $period['semester'] ),
			$period['year'],
			array(
				TAAS_Cycle_Stage::Student_Application->label() => array(
					'start_at' => new DateTimeImmutable( '@' . ( $now - 3600 ) ),
					'end_at'   => null === $end ? null : new DateTimeImmutable( '@' . $end ),
				),
			)
		)
	);
}
