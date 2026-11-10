<?php
/**
 * Local WordPress REST/MySQL checks for initial student applications.
 *
 * Run: docker compose exec -T WordPress php /var/www/taas/tests/integration/student-application-rest.php
 * Fixtures and changes to existing shared data are rolled back.
 *
 * @package TAAS_Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, WordPress.Security.EscapeOutput, WordPress.DB.DirectDatabaseQuery, WordPress.WP.GlobalVariablesOverride, WordPress.PHP.YodaConditions, Universal.Operators.StrictComparisons
// CLI checks intentionally use direct SQL, console diagnostics and JSON-semantic comparisons.

declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
require __DIR__ . '/support/application-cycle.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development mode is required.' );
}

/**
 * Check a guarantee.
 *
 * @param bool $condition Expected test condition.
 * @param string $message Failure description.
 */
function taas_application_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

/**
 * Dispatch a real registered REST route, including its permission callback.
 *
 * @param string $method Request method.
 * @param string $route REST route to dispatch.
 * @param array|string|null $body Decoded request body.
 */
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
$period        = array(
	'semester'  => 5,
	'year'      => 2154,
	'is_open'   => true,
	'closes_at' => time() + 3600,
);
$_GET['taas_role'] = 'student';
$owner = (string) TAAS_Auth::current_user()['university_id'];
$repos = array(
	'students'     => new TAAS_Student_Repository(),
	'profiles'     => new TAAS_Student_Profile_Repository(),
	'backgrounds'  => new TAAS_Academic_Background_Repository(),
	'courses'      => new TAAS_Course_Repository(),
	'professors'   => new TAAS_Professor_Repository(),
	'applications' => new TAAS_Application_Repository(),
	'submissions'  => new TAAS_Student_Submission_Repository(),
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
			taas_test_set_period( $period );
			$professor = (int) $repos['professors']->insert( new TAAS_Professor( null, null, 'API Test Professor', 'api-professor@example.invalid' ) );
			// Keep the assigned id, so re-inserting after the rollback below still matches the saved courses.
			$fixture_professor = $repos['professors']->find( $professor );
			$id = (string) $repos['courses']->insert( new TAAS_Course( null, 'TEST1000', 'Application API verification', $professor, TAAS_Semester::Summer_C, $period['year'] ) );
			$fixture_courses[] = $repos['courses']->find( $id );
			$other_id = (string) $repos['courses']->insert( new TAAS_Course( null, 'TEST2000', 'Other term', $professor, TAAS_Semester::Fall, $period['year'] ) );
			$body = array(
				'term'               => array(
					'semester' => $period['semester'],
					'year'     => $period['year'],
				),
				'details'            => array(
					'student_name'        => "API O'Brien",
					'student_email'       => TAAS_Auth::current_user()['email'],
					'profile'             => array(
						'phone'                => '3525550100',
						'country_code'         => 'US',
						'admitted_semester'    => 'Fall 2025',
						'available_hours'      => 10,
						'enrollment_status'    => 1,
						'fws_eligibility'      => 'yes',
						'fws_award_letter_url' => 'https://example.com/fws-letter',
						'eap5836_status'       => 1,
						'eap5837_status'       => 4,
						'resume_url'           => null,
						'english_test_score'   => 2,
						'additional_info'      => '001',
					),
					'academic_background' => array(
						array(
							'university'  => 'Example University',
							'degree_type' => 2,
							'major'       => 'CS',
							'grad_date'   => '2025-05-01',
							'gpa'         => 3.0,
						),
						array(
							'university'  => 'University of Florida',
							'degree_type' => 3,
							'major'       => 'CS',
							'grad_date'   => '2028-05-01',
							'gpa'         => null,
						),
					),
				),
				'course_preferences' => array(
					array(
						'course_id'            => $id,
						'rank'                 => 1,
						'has_completed_course' => true,
						'course_completion'    => 2,
						'has_ta_for_course'    => true,
					),
				),
			);
			$test_input = TAAS_Student_Application_Input::parse( $body );
			$forged_email = $body;
			$forged_email['details']['student_email'] = 'professor@ufl.edu';
			$rejected = taas_application_request( 'POST', '/student/application', $forged_email );
			taas_application_check( 400 === $rejected->get_status() && 'taas_student_email_mismatch' === $rejected->get_data()['code'], 'Initial submission rejects another university email' );
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
				if ( null !== $revision ) {
					$invalid_revision['revision'] = $revision; }
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
			$invalid['course_preferences'][0]['has_completed_course'] = 'false';
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid )->get_status(), 'Non-boolean course completion is rejected' );
			$invalid = $body;
			$invalid['details']['profile']['enrollment_status'] = 0;
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid )->get_status(), 'Not-enrolled status is rejected' );
			$invalid = $body;
			$invalid['course_preferences'][] = $invalid['course_preferences'][0];
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $invalid )->get_status(), 'Duplicate course/rank is rejected' );
			taas_test_set_period( array( 'is_open' => false ) + $period );
			taas_application_check( 403 === taas_application_request( 'POST', '/student/application', $body )->get_status(), 'Manual closure blocks submission' );
			taas_application_check( false === taas_application_request( 'GET', '/student/application' )->get_data()['period']['is_open'], 'Closed form data still loads and reports the closure' );
			taas_test_set_period( array( 'closes_at' => time() - 1 ) + $period );
			taas_application_check( 403 === taas_application_request( 'POST', '/student/application', $body )->get_status(), 'Passed deadline blocks submission without grace' );
			taas_test_set_period( $period );
			$missing_fws = $body;
			unset( $missing_fws['details']['profile']['fws_eligibility'] );
			taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $missing_fws )->get_status(), 'The REST API rejects unanswered undergraduate FWS eligibility' );
			foreach ( array( 'english_test_score' ) as $score_key ) {
				$missing_score = $body;
				unset( $missing_score['details']['profile'][ $score_key ] );
				taas_application_check( 400 === taas_application_request( 'POST', '/student/application', $missing_score )->get_status(), 'The REST API rejects missing ' . $score_key );
			}
			$created = taas_application_request( 'POST', '/student/application', $body );
			taas_application_check( 201 === $created->get_status(), 'Valid application is saved' );
			taas_application_check( TAAS_Enrollment_Status::Undergraduate === $repos['profiles']->find( $owner )->enrollment_status, 'Undergraduate application saves the shared enrollment status' );
			taas_application_check( 2 === $repos['profiles']->find( $owner )->to_row()['english_test_score'], 'English score category is stored as an integer' );
			$prefilled = taas_application_request( 'GET', '/student/application' )->get_data();
			taas_application_check( 1 === $prefilled['profile']['enrollment_status'], 'Undergraduate enrollment is returned for prefilling' );
			taas_application_check( 2 === $prefilled['english_test_score'], 'Prefill preserves the required category from the latest snapshot' );
			taas_application_check( 'yes' === $prefilled['profile']['fws_eligibility'] && 'https://example.com/fws-letter' === $prefilled['profile']['fws_award_letter_url'], 'REST prefill returns FWS eligibility and its separate award letter URL' );
			$read = taas_application_request( 'GET', '/student/application/5/' . $period['year'] );
			taas_application_check( 1 === $read->get_data()['details']['profile']['enrollment_status'], 'Undergraduate enrollment is preserved in the term snapshot' );
			taas_application_check( 200 === $read->get_status() && 2 === count( $read->get_data()['details']['academic_background'] ), 'Snapshot read preserves both degrees' );
			taas_application_check( 1 === $read->get_data()['course_preferences'][0]['match_status'], 'New course application is pending' );
			taas_application_check( true === $read->get_data()['course_preferences'][0]['has_ta_for_course'], 'Previous TA declaration is stored and returned for the selected course' );
			taas_application_check( 2 === $read->get_data()['course_preferences'][0]['course_completion'] && true === $read->get_data()['course_preferences'][0]['has_completed_course'], 'Equivalent coursework is saved and returned separately from Yes' );
			$changed_completion = $body;
			$changed_completion['course_preferences'][0]['course_completion'] = 1;
			taas_application_check( 409 === taas_application_request( 'POST', '/student/application', $changed_completion )->get_status(), 'A different completion answer is not treated as an identical retry' );
			$changed_experience = $body;
			$changed_experience['course_preferences'][0]['has_ta_for_course'] = false;
			taas_application_check( 409 === taas_application_request( 'POST', '/student/application', $changed_experience )->get_status(), 'A different TA declaration is not treated as an identical retry' );
			$retry = taas_application_request( 'POST', '/student/application', $body );
			taas_application_check( 200 === $retry->get_status() && $retry->get_data()['submission_id'] === $created->get_data()['submission_id'], 'Identical retry returns the original saved application' );

			$terms_response = taas_application_request( 'GET', '/student/application/terms' );
			foreach ( array(
				'form'       => $form,
				'created'    => $created,
				'retry'      => $retry,
				'saved read' => $read,
				'terms'      => $terms_response,
			) as $name => $response ) {
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
			taas_test_set_period( array() );
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
	$_GET = $original_get;
}
taas_application_check( $before === array_map( static fn( TAAS_Repository $repo ) => $repo->count(), $repos ), 'All verification rows rolled back' );
taas_application_check( $original_student === $repos['students']->find( $owner )?->to_row(), 'Original student identity restored' );
taas_application_check( $original_profile === $repos['profiles']->find( $owner )?->to_row(), 'Original shared profile restored' );
taas_application_check( $original_degrees === array_map( static fn( TAAS_Academic_Background $row ) => $row->to_row(), $repos['backgrounds']->find_by_student( $owner ) ), 'Original academic backgrounds restored' );

// Force a failure at the final snapshot write to verify the complete submission
// transaction rolls back identity, profile, degrees and course choices together.
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
			taas_test_set_period( $test_input['term'] + array( 'is_open' => true ) );
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
	$wpdb->suppress_errors( $previous_errors );
}
taas_application_check( $before === array_map( static fn( TAAS_Repository $repo ) => $repo->count(), $repos ), 'Database failure rolls back all application records' );
taas_application_check( $original_student === $repos['students']->find( $owner )?->to_row() && $original_profile === $repos['profiles']->find( $owner )?->to_row(), 'Database failure preserves original identity and profile' );
taas_application_check( $original_degrees === array_map( static fn( TAAS_Academic_Background $row ) => $row->to_row(), $repos['backgrounds']->find_by_student( $owner ) ), 'Database failure preserves original degrees' );
