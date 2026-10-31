<?php
/**
 * Local WordPress/MySQL integration checks for term submission storage.
 *
 * Run: docker compose exec -T wordpress php /var/www/taas/tests/integration/student-submissions.php
 * All fixtures are rolled back; requires the schema to be installed already.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require '/var/www/html/wp-load.php';

if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'These checks require local development mode.' );
}

/**
 * Fail immediately if a storage guarantee is broken.
 *
 * @param bool   $condition Expected result.
 * @param string $message   Guarantee being verified.
 * @return void
 */
function taas_submission_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

global $wpdb;
$repo          = new TAAS_Student_Submission_Repository();
$students      = new TAAS_Student_Repository();
$before        = $repo->count();
$test_students = array();
for ( $candidate = 99999999; $candidate >= 99999000 && count( $test_students ) < 2; --$candidate ) {
	if ( null === $students->find( (string) $candidate ) ) {
		$test_students[] = (string) $candidate;
	}
}
taas_submission_check( count( $test_students ) === 2, 'Unused fixture identifiers available' );

// Deliberately roll back even after successful checks; retain no test records.
taas_submission_check( false !== $wpdb->query( 'START TRANSACTION' ), 'Test transaction started' );
try {
	foreach ( $test_students as $ufid ) {
		$students->insert( new TAAS_Student( $ufid, 'Snapshot test', 'snapshot@example.invalid' ) );
	}
	$owner   = $test_students[0];
	$other   = $test_students[1];
	$details = array(
		'name'                => "Alex O'Brien",
		'email'               => 'alex@example.invalid',
		'profile'             => array( 'available_hours' => 10, 'resume_url' => null ),
		'academic_background' => array(
			array( 'university' => 'Université Example', 'gpa' => 3.5 ),
			array( 'university' => 'Second university', 'gpa' => null ),
		),
	);
	taas_submission_check( null === $repo->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2026 ), 'Missing submission returns null' );
	$fall_id = $repo->save_for_term( $owner, TAAS_Semester::Fall, 2026, $details );
	$fall    = $repo->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2026 );
	taas_submission_check( $fall_id > 0 && $fall->submission_id === $fall_id, 'Saved submission returns its generated ID' );
	taas_submission_check( $fall->submitted_details == $details, 'Names, Unicode, multiple degrees and null GPA round-trip' );
	taas_submission_check( null === $repo->find_by_student_and_term( $other, TAAS_Semester::Fall, 2026 ), 'Student lookup does not return another student details' );
	$spring_id = $repo->save_for_term( $owner, TAAS_Semester::Spring, 2027, array( 'profile' => array( 'available_hours' => 20 ) ) );
	taas_submission_check( $fall_id !== $spring_id, 'Different academic terms have separate records' );
	$replacement = array( 'profile' => array( 'available_hours' => 15 ) );
	taas_submission_check( $spring_id === $repo->save_for_term( $owner, TAAS_Semester::Spring, 2027, $replacement ), 'Replacing a term retains its submission ID' );
	taas_submission_check( $spring_id === $repo->save_for_term( $owner, TAAS_Semester::Spring, 2027, $replacement ), 'Retrying identical details retains the same ID' );
	taas_submission_check( $repo->find_by_student_and_term( $owner, TAAS_Semester::Spring, 2027 )->submitted_details === $replacement, 'Same-term replacement does not retain obsolete fields' );
	taas_submission_check( $repo->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2026 )->submitted_details == $details, 'Replacing Spring preserves Fall details' );
	$repo->save_for_term( $other, TAAS_Semester::Fall, 2026, array( 'name' => 'Other student' ) );
	taas_submission_check( $repo->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2026 )->submitted_details == $details, 'Other student saves preserve the owner details' );
	taas_submission_check( $repo->count() === $before + 3, 'Retries and replacements create no duplicate term rows' );
	try {
		$repo->save_for_term( $owner, TAAS_Semester::Fall, 2026, array( 'invalid' => INF ) );
		throw new RuntimeException( 'Invalid JSON values were accepted.' );
	} catch ( JsonException $e ) {
		echo 'PASS: Invalid JSON values rejected before writing' . PHP_EOL;
	}
	taas_submission_check( $repo->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2026 )->submitted_details == $details, 'Encoding failure preserves existing details' );
} finally {
	$wpdb->query( 'ROLLBACK' );
}
taas_submission_check( $repo->count() === $before, 'Verification snapshots rolled back' );
foreach ( $test_students as $ufid ) {
	taas_submission_check( null === $students->find( $ufid ), 'Verification student rolled back' );
}
