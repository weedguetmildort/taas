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
function taas_application_request( string $method, string $route, array|string|null $body = null ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/taas/v1' . $route );
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
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
						'resume_url' => null, 'english_test' => array( 'type' => 'TOEFL', 'score' => 5.5 ), 'additional_info' => '001',
					),
					'academic_background' => array(
						array( 'university' => 'Example University', 'degree_type' => 2, 'major' => 'CS', 'grad_date' => '2025-05-01', 'gpa' => 3.0 ),
						array( 'university' => 'University of Florida', 'degree_type' => 3, 'major' => 'CS', 'grad_date' => '2028-05-01', 'gpa' => null ),
					),
				),
				'course_preferences' => array( array( 'course_id' => $id, 'rank' => 1, 'has_completed_course' => true ) ),
			);
			$test_input = TAAS_Student_Application_Input::parse( $body );
			$_GET['taas_role'] = 'instructor';
			taas_application_check( 403 === taas_application_request( 'GET', '/student/application' )->get_status(), 'Instructor cannot read student form data' );
			taas_application_check( 403 === taas_application_request( 'POST', '/student/application', $body )->get_status(), 'Instructor cannot submit as a student' );
			foreach ( array( 'GET', 'POST', 'PATCH', 'PUT' ) as $method ) {
				taas_application_check( 403 === taas_application_request( $method, '/student/application/5/' . $period['year'], $body )->get_status(), 'Saved application route requires student permission for ' . $method );
			}
			taas_application_check( 403 === taas_application_request( 'GET', '/student/application/terms' )->get_status(), 'Term list requires student permission' );
			$_GET['taas_role'] = 'student';

			foreach ( array( '/student/application', '/student/application/5/' . $period['year'] ) as $route ) {
				foreach ( array( '{', 'null', '[]', '"invalid"' ) as $json ) {
					taas_application_check( 400 === taas_application_request( 'POST', $route, $json )->get_status(), 'Malformed/non-object JSON rejected: ' . $route . ' / ' . $json );
				}
			}
			foreach ( array( 'PATCH', 'PUT' ) as $method ) {
				taas_application_check( 400 === taas_application_request( $method, '/student/application/5/' . $period['year'], $body )->get_status(), $method . ' edit route remains registered and validates revisions' );
			}
			foreach ( array( null, '', 'bad-revision', 123 ) as $revision ) {
				$invalid_revision = $body;
				if ( null !== $revision ) { $invalid_revision['revision'] = $revision; }
				$error = taas_application_request( 'POST', '/student/application/5/' . $period['year'], $invalid_revision );
				taas_application_check( 400 === $error->get_status() && 'taas_invalid_revision' === $error->get_data()['code'], 'Missing or invalid revision returns the expected 400 error' );
			}
			foreach ( array( '0000', '1900', '2156', '9999' ) as $year ) {
				taas_application_check( 400 === taas_application_request( 'GET', '/student/application/5/' . $year )->get_status(), 'Invalid route year rejected: ' . $year );
			}
			$form = taas_application_request( 'GET', '/student/application' );
			taas_application_check( 200 === $form->get_status() && $form->get_data()['identity']['ufid'] === $owner, 'Prefill uses authenticated identity' );
			$ids = array_column( $form->get_data()['courses'], 'course_id' );
			taas_application_check( in_array( $id, $ids, true ) && ! in_array( $other_id, $ids, true ), 'Course list is filtered to configured term' );

			foreach ( array( 'ZZ', 'XX', 'AA', 'U1', 'USA', '' ) as $country ) {
				$invalid_country = $body;
				$invalid_country['details']['profile']['country_code'] = $country;
				taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid_country )->get_status(), 'Unsupported country rejected: ' . $country );
			}
			// Every country offered by the frontend must pass backend validation.
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/htdocs/wp-content/plugins/taas-student-application-page/assets/application-fields.js' );
			preg_match( '/const COUNTRY_CODES = \[(.*?)\]\.join/s', $source, $country_block );
			preg_match_all( "/'([A-Z ]+)'/", $country_block[1], $country_rows );
			$country_codes = explode( ' ', implode( ' ', $country_rows[1] ) );
			taas_application_check( 249 === count( $country_codes ), 'Frontend country list is loaded for parity verification' );
			foreach ( $country_codes as $country ) {
				$valid_country = $body;
				$valid_country['details']['profile']['country_code'] = strtolower( $country );
				$parsed_country = TAAS_Student_Application_Input::parse( $valid_country );
				if ( $country !== $parsed_country['details']['profile']['country_code'] ) {
					throw new RuntimeException( 'Country normalization mismatch: ' . $country );
				}
			}
			taas_application_check( true, 'All 249 frontend country codes are accepted and normalized to uppercase' );
			foreach ( array( "\0" . '3525550100', '3525550100' . "\0", '352' . "\0" . '5550100' ) as $phone ) {
				$invalid_text = $body;
				$invalid_text['details']['profile']['phone'] = $phone;
				taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid_text )->get_status(), 'NUL characters are rejected before trimming text' );
			}
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
			$read = taas_application_request( 'GET', '/student/application/5/' . $period['year'] );
			taas_application_check( 200 === $read->get_status() && 2 === count( $read->get_data()['details']['academic_background'] ), 'Snapshot read preserves both degrees' );
			taas_application_check( 1 === $read->get_data()['course_preferences'][0]['match_status'], 'New course application is pending' );
			$retry = taas_application_request( 'POST', '/student/application', $body );
			taas_application_check( 200 === $retry->get_status() && $retry->get_data()['submission_id'] === $created->get_data()['submission_id'], 'Identical retry returns the original saved application' );

			$terms_response = taas_application_request( 'GET', '/student/application/terms' );
			foreach ( array( 'form' => $form, 'created' => $created, 'retry' => $retry, 'saved read' => $read, 'terms' => $terms_response ) as $name => $response ) {
				$headers = array_change_key_case( $response->get_headers(), CASE_LOWER );
				taas_application_check( 'no-store' === ( $headers['cache-control'] ?? null ), 'Personal response prevents caching: ' . $name );
			}

			$equivalent = $body;
			$equivalent['details'] = array_reverse( $equivalent['details'], true );
			$equivalent['details']['profile'] = array_reverse( $equivalent['details']['profile'], true );
			$equivalent['details']['academic_background'][0]['gpa'] = 3;
			taas_application_check( 200 === taas_application_request( 'POST', '/student/application', $equivalent )->get_status(), 'Equivalent numbers and reordered object keys remain an identical retry' );
			$different_text = $body;
			$different_text['details']['profile']['additional_info'] = '1';
			$conflict = taas_application_request( 'POST', '/student/application', $different_text );
			taas_application_check( 409 === $conflict->get_status(), 'Different numeric-looking text is a conflict, not an identical retry' );
			$stored = $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_C, $period['year'] );
			taas_application_check( '001' === $stored->submitted_details['profile']['additional_info'], 'Rejected text changes preserve the saved snapshot' );
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
