<?php
/** Regression checks for TOEFL metadata during application edits; all fixtures roll back. */
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }

function taas_profile_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}

$students = new TAAS_Student_Repository();
$profiles = new TAAS_Student_Profile_Repository();
$submissions = new TAAS_Student_Submission_Repository();
$owner = '99993000';
while ( $students->find( $owner ) ) { $owner = (string) ( (int) $owner - 1 ); }
$period = array( 'semester' => 1, 'year' => 2155, 'is_open' => true, 'closes_at' => null );
$filter = static fn() => $period;
add_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $filter );
$body = array(
	'term' => array( 'semester' => 1, 'year' => 2155 ),
	'details' => array(
		'student_name' => 'Profile verification', 'student_email' => 'profile@ufl.edu',
		'profile' => array( 'phone' => '3525550100', 'country_code' => 'US', 'admitted_semester' => 'Fall 2025', 'available_hours' => 10, 'enrollment_status' => 3, 'eap5836_status' => 1, 'eap5837_status' => 4, 'english_test' => array( 'type' => 'TOEFL', 'score' => 6 ) ),
		'academic_background' => array( array( 'university' => 'Example University', 'degree_type' => 2, 'major' => 'CS', 'grad_date' => '2025-05-01', 'gpa' => 3.5 ) ),
	),
	'course_preferences' => array(),
);
$sentinel = new RuntimeException( 'Roll back profile verification.' );
try {
	TAAS_Transaction::run( static function () use ( $students, $profiles, $submissions, $owner, $body, $sentinel ): void {
		global $wpdb;
		$students->insert( new TAAS_Student( $owner, 'Profile verification', 'profile@ufl.edu' ) );
		$profiles->insert( new TAAS_Student_Profile( student_ufid: $owner, toefl_ibt_score: 6, toefl_ibt_date: new DateTimeImmutable( '2026-02-10' ) ) );
		$submissions->save_for_term( $owner, TAAS_Semester::Fall, 2155, TAAS_Student_Application_Input::parse( $body, true )['details'] );
		$save = static function ( array $request ) use ( $owner, $submissions ): void {
			$snapshot = $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 );
			TAAS_Student_Application_Service::update( $owner, TAAS_Student_Application_Input::parse( $request, true ), TAAS_Student_Application_Service::revision( $snapshot, array() ) );
		};
		$body['details']['profile']['phone'] = '3525550199';
		$save( $body );
		taas_profile_check( '2026-02-10' === $profiles->find( $owner )->toefl_ibt_date?->format( 'Y-m-d' ), 'An unrelated phone edit preserves the unchanged TOEFL test date' );
		taas_profile_check( '3525550199' === $profiles->find( $owner )->phone, 'The requested profile edit still saves' );
		$body['details']['profile']['english_test']['score'] = 5;
		$save( $body );
		taas_profile_check( null === $profiles->find( $owner )->toefl_ibt_date && 5 === $profiles->find( $owner )->toefl_ibt_score, 'Changing the score does not reuse the previous test date' );
		$wpdb->update( $profiles->table(), array( 'toefl_ibt_date' => '2026-03-10' ), array( 'student_ufid' => $owner ) );
		$body['details']['profile']['english_test']['type'] = 'SPEAK';
		$save( $body );
		taas_profile_check( null === $profiles->find( $owner )->toefl_ibt_date && null === $profiles->find( $owner )->toefl_ibt_score, 'Switching test type clears the old TOEFL metadata' );
		$wpdb->update( $profiles->table(), array( 'toefl_ibt_score' => 6, 'toefl_ibt_date' => '2026-04-10' ), array( 'student_ufid' => $owner ) );
		$body['details']['profile']['english_test'] = null;
		$save( $body );
		taas_profile_check( null === $profiles->find( $owner )->toefl_ibt_date && null === $profiles->find( $owner )->toefl_ibt_score, 'Removing the English test clears its TOEFL metadata' );
		throw $sentinel;
	} );
} catch ( Throwable $error ) {
	if ( $error !== $sentinel ) { throw $error; }
} finally {
	remove_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $filter );
}
taas_profile_check( null === $students->find( $owner ) && null === $profiles->find( $owner ) && null === $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 ), 'All profile-regression fixtures rolled back' );
