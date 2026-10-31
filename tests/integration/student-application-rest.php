<?php
/**
 * Local WordPress REST/MySQL checks for initial student applications.
 *
 * Run: docker compose exec -T wordpress php /var/www/taas/tests/integration/student-application-rest.php
 * Fixtures and changes to existing shared data are rolled back.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development mode is required.' );
}

/** Check a guarantee. */
function taas_application_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

/** Dispatch a real registered REST route, including its permission callback. */
function taas_application_request( string $method, string $route, ?array $body = null ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/taas/v1' . $route );
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_get_server()->dispatch( $request );
}

global $wpdb;
$original_get   = $_GET;
$period        = array( 'semester' => 5, 'year' => 2154, 'is_open' => true, 'closes_at' => time() + 3600 );
$period_filter = static function () use ( &$period ) { return $period; };
add_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $period_filter );
$_GET['taas_role'] = 'student';
$owner = (string) TAAS_Auth::current_user()['university_id'];
$repos = array(
	'students'    => new TAAS_Student_Repository(),
	'profiles'    => new TAAS_Student_Profile_Repository(),
	'backgrounds' => new TAAS_Academic_Background_Repository(),
	'courses'     => new TAAS_Course_Repository(),
	'professors'  => new TAAS_Professor_Repository(),
	'applications' => new TAAS_Application_Repository(),
	'submissions' => new TAAS_Student_Submission_Repository(),
);
$before = array_map( static fn( TAAS_Repository $repo ) => $repo->count(), $repos );
$original_student = $repos['students']->find( $owner )?->to_row();
$original_profile = $repos['profiles']->find( $owner )?->to_row();
$original_degrees = array_map( static fn( TAAS_Academic_Background $row ) => $row->to_row(), $repos['backgrounds']->find_by_student( $owner ) );
$sentinel = new RuntimeException( 'Roll back successful verification fixtures.' );
$test_input = null;
$fixture_professor = null;
$fixture_courses = array();
try {
	TAAS_Transaction::run(
		static function () use ( &$period, $owner, $repos, $sentinel, &$test_input, &$fixture_professor, &$fixture_courses ) {
			while ( null !== $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_C, $period['year'] )
				|| $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_C, $period['year'] ) ) {
				--$period['year'];
			}
			$professor = '99999000';
			while ( null !== $repos['professors']->find( $professor ) ) {
				$professor = (string) ( (int) $professor - 1 );
			}
			$fixture_professor = new TAAS_Professor( $professor, 'API Test Professor', 'api-professor@example.invalid' );
			$repos['professors']->insert( $fixture_professor );
			$id = 'test-' . bin2hex( random_bytes( 5 ) );
			$fixture_courses[] = new TAAS_Course( $id, 'TEST1000', 'Application API verification', $professor, TAAS_Semester::Summer_C, $period['year'] );
			$repos['courses']->insert( $fixture_courses[0] );
			$other_id = 'test-' . bin2hex( random_bytes( 5 ) );
			$repos['courses']->insert( new TAAS_Course( $other_id, 'TEST2000', 'Other term', $professor, TAAS_Semester::Fall, $period['year'] ) );
			$body = array(
				'term'    => array( 'semester' => $period['semester'], 'year' => $period['year'] ),
				'details' => array(
					'student_name'  => "API O'Brien",
					'student_email' => 'api.student@ufl.edu',
					'profile' => array(
						'phone' => '3525550100', 'country_code' => 'US', 'admitted_semester' => 'Fall 2025',
						'available_hours' => 10, 'enrollment_status' => 3, 'eap5836_status' => 1, 'eap5837_status' => 4,
						'resume_url' => null, 'english_test' => array( 'type' => 'TOEFL', 'score' => 5.5 ), 'additional_info' => '',
					),
					'academic_background' => array(
						array( 'university' => 'Example University', 'degree_type' => 2, 'major' => 'CS', 'grad_date' => '2025-05-01', 'gpa' => 3.5 ),
						array( 'university' => 'University of Florida', 'degree_type' => 3, 'major' => 'CS', 'grad_date' => '2028-05-01', 'gpa' => null ),
					),
				),
				'course_preferences' => array( array( 'course_id' => $id, 'rank' => 1, 'has_completed_course' => true ) ),
			);
			$test_input = TAAS_Student_Application_Input::parse( $body );
			$_GET['taas_role'] = 'instructor';
			taas_application_check( 403 === taas_application_request( 'GET', '/student/application' )->get_status(), 'Instructor cannot read student form data' );
			taas_application_check( 403 === taas_application_request( 'POST', '/student/application', $body )->get_status(), 'Instructor cannot submit as a student' );
			$_GET['taas_role'] = 'student';
			$form = taas_application_request( 'GET', '/student/application' );
			taas_application_check( 200 === $form->get_status() && $form->get_data()['identity']['ufid'] === $owner, 'Prefill uses authenticated identity' );
			$ids = array_column( $form->get_data()['courses'], 'course_id' );
			taas_application_check( in_array( $id, $ids, true ) && ! in_array( $other_id, $ids, true ), 'Course list is filtered to configured term' );
			$invalid = $body;
			$invalid['student_ufid'] = '87654321';
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid )->get_status(), 'Forged owner field is rejected' );
			$invalid = $body;
			$invalid['course_preferences'][0]['course_id'] = $other_id;
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid )->get_status(), 'Other-term course is rejected' );
			$invalid = $body;
			$invalid['course_preferences'][0]['has_completed_course'] = false;
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid )->get_status(), 'Incomplete course is rejected' );
			$invalid = $body;
			$invalid['details']['profile']['enrollment_status'] = 2;
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid )->get_status(), 'Unresolved program mapping is rejected' );
			$invalid = $body;
			$invalid['course_preferences'][] = $invalid['course_preferences'][0];
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid )->get_status(), 'Duplicate course/rank is rejected' );
			$period['is_open'] = false;
			taas_application_check( 403 === taas_application_request( 'POST', '/student/application', $body )->get_status(), 'Manual closure blocks submission' );
			$period['is_open'] = true;
			$period['closes_at'] = time() - 1;
			taas_application_check( 403 === taas_application_request( 'POST', '/student/application', $body )->get_status(), 'Passed deadline blocks submission without grace' );
			$period['closes_at'] = time() + 3600;
			$created = taas_application_request( 'POST', '/student/application', $body );
			taas_application_check( 201 === $created->get_status(), 'Valid application is saved' );
			taas_application_check( 6 === $repos['profiles']->find( $owner )->toefl_ibt_score, 'Fractional English score is rounded for storage' );
			$prefilled = taas_application_request( 'GET', '/student/application' )->get_data();
			taas_application_check( $prefilled['english_test'] === array( 'type' => 'TOEFL', 'score' => 6 ), 'Prefill preserves English test type from the latest snapshot' );
			$read = taas_application_request( 'GET', '/student/applications/5/' . $period['year'] );
			taas_application_check( 200 === $read->get_status() && 2 === count( $read->get_data()['details']['academic_background'] ), 'Snapshot read preserves both degrees' );
			taas_application_check( 1 === $read->get_data()['course_preferences'][0]['match_status'], 'New course application is pending' );
			$retry = taas_application_request( 'POST', '/student/application', $body );
			taas_application_check( 200 === $retry->get_status() && $retry->get_data()['submission_id'] === $created->get_data()['submission_id'], 'Identical retry returns the original saved application' );
			$changed = $body;
			$changed['details']['profile']['available_hours'] = 20;
			taas_application_check( 409 === taas_application_request( 'POST', '/student/application', $changed )->get_status(), 'Initial submission cannot overwrite existing preferences' );
			taas_application_check( 10 === $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_C, $period['year'] )->submitted_details['profile']['available_hours'], 'Conflict preserves the saved term snapshot' );
			$period = array();
			taas_application_check( 503 === taas_application_request( 'GET', '/student/application' )->get_status(), 'Missing configuration is explicit, not an invented term' );
			throw $sentinel;
		}
	);
} catch ( Throwable $error ) {
	if ( $error !== $sentinel ) {
		fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
} finally {
	remove_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $period_filter );
	$_GET = $original_get;
}
taas_application_check( $before === array_map( static fn( TAAS_Repository $repo ) => $repo->count(), $repos ), 'All verification rows rolled back' );
taas_application_check( $original_student === $repos['students']->find( $owner )?->to_row(), 'Original student identity restored' );
taas_application_check( $original_profile === $repos['profiles']->find( $owner )?->to_row(), 'Original shared profile restored' );
taas_application_check( $original_degrees === array_map( static fn( TAAS_Academic_Background $row ) => $row->to_row(), $repos['backgrounds']->find_by_student( $owner ) ), 'Original academic backgrounds restored' );

// Force a failure at the final snapshot write to verify the complete submission
// transaction rolls back identity, profile, degrees and course choices together.
$period = $test_input['term'] + array( 'is_open' => true, 'closes_at' => time() + 3600 );
add_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $period_filter );
$fail_snapshot = static function ( string $query ) use ( $repos ): string {
	if ( str_contains( $query, $repos['submissions']->table() ) && preg_match( '/^\s*INSERT INTO/i', $query ) ) {
		return 'DELIBERATELY INVALID SQL FOR ROLLBACK VERIFICATION';
	}
	return $query;
};
$previous_errors = $wpdb->suppress_errors( true );
try {
	TAAS_Transaction::run(
		static function () use ( $repos, $fixture_professor, $fixture_courses, $owner, $test_input, $fail_snapshot ) {
			$repos['professors']->insert( $fixture_professor );
			foreach ( $fixture_courses as $course ) {
				$repos['courses']->insert( $course );
			}
			add_filter( 'query', $fail_snapshot );
			TAAS_Student_Application_Service::submit( $owner, $test_input );
			throw new LogicException( 'Expected an injected persistence failure.' );
		}
	);
} catch ( RuntimeException $error ) {
	taas_application_check( str_contains( $error->getMessage(), 'Saving student submission failed' ), 'Snapshot write failure is surfaced' );
} finally {
	remove_filter( 'query', $fail_snapshot );
	remove_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $period_filter );
	$wpdb->suppress_errors( $previous_errors );
}
taas_application_check( $before === array_map( static fn( TAAS_Repository $repo ) => $repo->count(), $repos ), 'Database failure rolls back all application records' );
taas_application_check( $original_student === $repos['students']->find( $owner )?->to_row() && $original_profile === $repos['profiles']->find( $owner )?->to_row(), 'Database failure preserves original identity and profile' );
taas_application_check( $original_degrees === array_map( static fn( TAAS_Academic_Background $row ) => $row->to_row(), $repos['backgrounds']->find_by_student( $owner ) ), 'Database failure preserves original degrees' );
