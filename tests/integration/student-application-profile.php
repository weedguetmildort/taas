<?php
/**
 * Regression checks for required language scores and optional dates during application edits; all fixtures roll back.
 *
 * @package TAAS_Tests
 */

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, WordPress.Security.EscapeOutput, WordPress.DB.DirectDatabaseQuery, WordPress.WP.GlobalVariablesOverride, WordPress.PHP.YodaConditions, Universal.Operators.StrictComparisons
// CLI checks intentionally use direct SQL, console diagnostics and JSON-semantic comparisons.

declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 ); }
require '/var/www/html/wp-load.php';
require __DIR__ . '/support/application-cycle.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development required.' ); }

function taas_profile_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}

$students = new TAAS_Student_Repository();
$profiles = new TAAS_Student_Profile_Repository();
$submissions = new TAAS_Student_Submission_Repository();
$owner = '99993000';
while ( $students->find( $owner ) ) {
	$owner = (string) ( (int) $owner - 1 ); }
$period = array(
	'semester'  => 1,
	'year'      => 2155,
	'is_open'   => true,
	'closes_at' => null,
);
$body = array(
	'term'               => array(
		'semester' => 1,
		'year'     => 2155,
	),
	'details'            => array(
		'student_name'        => 'Profile verification',
		'student_email'       => 'profile@ufl.edu',
		'profile'             => array(
			'phone'              => '3525550100',
			'country_code'       => 'US',
			'admitted_semester'  => 'Fall 2025',
			'available_hours'    => 10,
			'enrollment_status'  => 2,
			'eap5836_status'     => 1,
			'eap5837_status'     => 3,
			'english_test_score' => 2,
		),
		'academic_background' => array(
			array(
				'university'  => 'Example University',
				'degree_type' => 2,
				'major'       => 'CS',
				'grad_date'   => '2025-05-01',
				'gpa'         => 3.5,
			),
		),
	),
	'course_preferences' => array(),
);
$sentinel = new RuntimeException( 'Roll back profile verification.' );
try {
	TAAS_Transaction::run(
		static function () use ( $students, $profiles, $submissions, $owner, $body, $period, $sentinel ): void {
			global $wpdb;
			taas_test_set_period( $period );
			$students->insert( new TAAS_Student( $owner, 'Profile verification', 'profile@ufl.edu' ) );
			$profiles->insert( new TAAS_Student_Profile( student_ufid: $owner, english_test_score: TAAS_English_Test_Score::Middle_Range ) );
			$submissions->save_for_term( $owner, TAAS_Semester::Fall, 2155, TAAS_Student_Application_Input::parse( $body, true )['details'] );
			$save = static function ( array $request ) use ( $owner, $submissions ): void {
				$snapshot = $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 );
				TAAS_Student_Application_Service::update( $owner, TAAS_Student_Application_Input::parse( $request, true ), TAAS_Student_Application_Service::revision( $snapshot, array() ) );
			};
				$body['details']['profile']['phone'] = '3525550199';
				$body['details']['academic_background'][] = array(
					'university'  => 'Previous University',
					'degree_type' => 1,
					'major'       => 'Mathematics',
					'grad_date'   => '2020-05-01',
					'gpa'         => 3.9,
				);
			$save( $body );
			taas_profile_check( 'CS' === $profiles->find( $owner )->major && 3.5 === $profiles->find( $owner )->gpa, 'The first degree supplies the shared major and GPA, rather than a previous degree' );
			$snapshot = $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 );
			taas_profile_check( 2 === count( $snapshot->submitted_details['academic_background'] ), 'The snapshot preserves both current and previous degrees' );
			taas_profile_check( TAAS_English_Test_Score::Middle_Range === $profiles->find( $owner )->english_test_score, 'An unrelated edit preserves the English score category' );
			taas_profile_check( '3525550199' === $profiles->find( $owner )->phone, 'The requested profile edit still saves' );
			$exam_fields = array(
				'english_test_score' => 2,
			);
			$body['details']['profile'] = array_replace( $body['details']['profile'], $exam_fields );
			$save( $body );
			$stored = $profiles->find( $owner )->to_row();
			$snapshot = $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 );
			foreach ( $exam_fields as $key => $value ) {
				taas_profile_check( $value === $stored[ $key ] && $value === $snapshot->submitted_details['profile'][ $key ], 'Both exam results are stored in the shared profile and snapshot: ' . $key );
			}
			$other_details = $snapshot->submitted_details;
			$submissions->save_for_term( $owner, TAAS_Semester::Spring, 2155, $other_details );
			$body['details']['profile']['english_test_score'] = 4;
			$save( $body );
			$stored = $profiles->find( $owner )->to_row();
			$snapshot = $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 );
			taas_profile_check( 4 === $stored['english_test_score'] && 4 === $snapshot->submitted_details['profile']['english_test_score'] && ! array_key_exists( 'toefl_ibt_date', $stored ) && ! array_key_exists( 'speak_date', $snapshot->submitted_details['profile'] ), 'N/A is preserved as category 4 without storing exam dates' );
			$before_details = $snapshot->submitted_details;
			foreach ( array( 'english_test_score' ) as $score_key ) {
				$invalid = $body;
				$invalid['details']['profile'][ $score_key ] = null;
				try {
					$save( $invalid );
					throw new LogicException( 'An edit cleared a required score.' );
				} catch ( InvalidArgumentException $error ) {
					taas_profile_check( $stored === $profiles->find( $owner )->to_row() && $before_details === $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 )->submitted_details, 'Rejecting an empty score preserves the shared profile and snapshot: ' . $score_key );
				}
			}
			taas_profile_check( $other_details === $submissions->find_by_student_and_term( $owner, TAAS_Semester::Spring, 2155 )->submitted_details, 'Editing language scores preserves other terms completely' );
			$body['details']['academic_background'][0]['major'] = 'Computer Engineering';
			$body['details']['academic_background'][0]['gpa'] = null;
			$save( $body );
			taas_profile_check( 'Computer Engineering' === $profiles->find( $owner )->major && null === $profiles->find( $owner )->gpa, 'An updated first degree replaces the shared major and clears an unavailable GPA' );
			$backgrounds = new TAAS_Academic_Background_Repository();
			$saved_degrees = $backgrounds->find_by_student( $owner );
			$before_degrees = array_map( static fn( TAAS_Academic_Background $degree ) => $degree->to_row(), $saved_degrees );
			$fresh_entry = new TAAS_Academic_Background(
				background_id: null,
				student_ufid: $owner,
				university: 'New University',
				degree_type: TAAS_Degree_Type::from( 3 ),
				grad_date: new DateTimeImmutable( '2027-05-01' )
			);
			try {
				$backgrounds->replace_for_student( $owner, array( $fresh_entry, $saved_degrees[1] ) );
				throw new LogicException( 'Replacement accepted an existing degree ID.' );
			} catch ( InvalidArgumentException $error ) {
				taas_profile_check( 'Replacement academic-background entries must have null IDs.' === $error->getMessage(), 'Replacement rejects an existing ID even after a valid new entry' );
			}
			taas_profile_check( $before_degrees === array_map( static fn( TAAS_Academic_Background $degree ) => $degree->to_row(), $backgrounds->find_by_student( $owner ) ), 'Rejected replacement preserves all saved degrees and their IDs' );
			$body['details']['academic_background'] = array_reverse( $body['details']['academic_background'] );
			$save( $body );
			$reordered_degrees = $backgrounds->find_by_student( $owner );
			taas_profile_check(
				array( 'Mathematics', 'Computer Engineering' ) === array_map( static fn( TAAS_Academic_Background $degree ) => $degree->major, $reordered_degrees )
				&& $reordered_degrees[0]->background_id > $saved_degrees[1]->background_id,
				'Reordering through the application creates fresh IDs and preserves the new degree order'
			);
			taas_profile_check( 'Mathematics' === $profiles->find( $owner )->major && 3.9 === $profiles->find( $owner )->gpa, 'Reordering makes the new first degree supply the shared profile summary' );
			$body['details']['profile']['enrollment_status'] = 1;
			$body['details']['profile']['fws_eligibility'] = 'yes';
			$body['details']['profile']['fws_award_letter_url'] = 'https://example.com/fws-letter';
			$body['details']['profile']['resume_url'] = 'https://example.com/resume';
			$save( $body );
			$stored = $profiles->find( $owner );
			$fws_details = $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 )->submitted_details;
			taas_profile_check( 'yes' === $stored->fws_eligibility && 'https://example.com/fws-letter' === $stored->fws_award_letter_url && 'https://example.com/resume' === $stored->resume_url, 'FWS eligibility and award letter persist separately from the resume' );
			taas_profile_check( 'yes' === $fws_details['profile']['fws_eligibility'] && $stored->fws_award_letter_url === $fws_details['profile']['fws_award_letter_url'], 'FWS data is included in the saved application snapshot' );
			$submissions->save_for_term( $owner, TAAS_Semester::Spring, 2155, $fws_details );
			$body['details']['profile']['enrollment_status'] = 2;
			$save( $body );
			$stored = $profiles->find( $owner );
			$graduate = $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 )->submitted_details['profile'];
			taas_profile_check( 'no' === $stored->fws_eligibility && null === $stored->fws_award_letter_url && 'no' === $graduate['fws_eligibility'] && null === $graduate['fws_award_letter_url'], 'Saving graduate status sets FWS eligibility to no and clears the award letter in the shared profile and edited term' );
			taas_profile_check( 'https://example.com/resume' === $stored->resume_url && $fws_details === $submissions->find_by_student_and_term( $owner, TAAS_Semester::Spring, 2155 )->submitted_details, 'Graduate clearing preserves the resume and earlier terms including their FWS data' );

			throw $sentinel;
		}
	);
} catch ( Throwable $error ) {
	if ( $error !== $sentinel ) {
		throw $error; }
}
taas_profile_check( null === $students->find( $owner ) && null === $profiles->find( $owner ) && null === $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, 2155 ), 'All profile-regression fixtures rolled back' );
