<?php
/**
 * Verify demo applications have term snapshots and repeated imports are harmless.
 * Run against an installed local schema; all inserted demo fixtures roll back.
 *
 * @package TAAS_Tests
 */

// phpcs:disable WordPress.Security.EscapeOutput, Universal.Operators.StrictComparisons
// CLI diagnostics and JSON-semantic comparisons are intentional in this harness.

declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development required.' );
}

$before = TAAS_Schema::counts();
$sentinel = new RuntimeException( 'Roll back demo snapshot verification.' );
try {
	TAAS_Transaction::run(
		static function () use ( $sentinel ): void {
			TAAS_Seeds::import( 'demo-data.json' );
			$after_first = TAAS_Schema::counts();
			TAAS_Seeds::import( 'demo-data.json' );
			if ( TAAS_Schema::counts() !== $after_first ) {
				throw new RuntimeException( 'Repeated imports created duplicate demo records.' );
			}
			echo "PASS: Repeated demo imports do not duplicate term snapshots or other records\n";

			$fixture = json_decode( file_get_contents( TAAS_CORE_PATH . 'seeds/demo-data.json' ), true, 512, JSON_THROW_ON_ERROR );
			$submissions = new TAAS_Student_Submission_Repository();
			foreach ( $fixture['student_submissions'] as $expected ) {
				$actual = $submissions->find_by_student_and_term( $expected['student_ufid'], TAAS_Semester::from( $expected['semester'] ), $expected['year'] );
				$details = json_decode( $expected['submitted_details'], true, 512, JSON_THROW_ON_ERROR );
				if ( null === $actual || $actual->submitted_details != $details ) {
					throw new RuntimeException( 'A demo snapshot is missing or its JSON did not round-trip.' );
				}
				if ( ! str_contains( $actual->submitted_details['profile']['additional_info'], 'Demo snapshot fixture for local testing' ) ) {
					throw new RuntimeException( 'Synthetic demo snapshots must be explicitly labeled.' );
				}
			}
			echo "PASS: Demo snapshots preserve their details and are explicitly labeled as synthetic\n";

			$applications = new TAAS_Application_Repository();
			foreach ( $fixture['courses'] as $course ) {
				$applicants = $applications->applicants_for_course( $course['course_id'] );
				foreach ( $applicants as $applicant ) {
					if ( array_key_exists( 'student_ufid', $applicant ) ) {
						throw new RuntimeException( 'Professor applicant summaries must not expose UFIDs.' );
					}
				}
			}
			echo "PASS: Seeded professor applicant lists load term snapshots without exposing UFIDs\n";
			throw $sentinel;
		}
	);
} catch ( Throwable $error ) {
	if ( $error !== $sentinel ) {
		throw $error;
	}
}
if ( TAAS_Schema::counts() !== $before ) {
	throw new RuntimeException( 'Demo verification changed persistent row counts.' );
}
echo "PASS: Demo snapshot verification fixtures rolled back\n";
