<?php
/**
 * Student-owned application editing checks against local WordPress/MySQL.
 *
 * Run: docker compose exec -T WordPress php /var/www/taas/tests/integration/student-application-edits.php
 * All fixtures and changes to existing shared data are rolled back.
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
 * Assert a storage or access guarantee.
 *
 * @param bool $condition Expected test condition.
 * @param string $message Failure description.
 */
function taas_edit_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

/**
 * Dispatch a registered route, including permission checks.
 *
 * @param string $method Request method.
 * @param string $route REST route to dispatch.
 * @param ?array $body Decoded request body.
 */
function taas_edit_request( string $method, string $route, ?array $body = null ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/taas/v1' . $route );
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_get_server()->dispatch( $request );
}

global $wpdb;
$original_get = $_GET;
$_GET['taas_role'] = 'student';
$owner = (string) TAAS_Auth::current_user()['university_id'];
$period = array(
	'semester'  => 3,
	'year'      => 2150,
	'is_open'   => true,
	'closes_at' => time() + 3600,
);
$repos = array(
	'students'     => new TAAS_Student_Repository(),
	'profiles'     => new TAAS_Student_Profile_Repository(),
	'backgrounds'  => new TAAS_Academic_Background_Repository(),
	'applications' => new TAAS_Application_Repository(),
	'submissions'  => new TAAS_Student_Submission_Repository(),
	'courses'      => new TAAS_Course_Repository(),
	'professors'   => new TAAS_Professor_Repository(),
);
$before = array_map( static fn( TAAS_Repository $repo ) => $repo->count(), $repos );
$old_student = $repos['students']->find( $owner )?->to_row();
$old_profile = $repos['profiles']->find( $owner )?->to_row();
$old_degrees = array_map( static fn( TAAS_Academic_Background $entry ) => $entry->to_row(), $repos['backgrounds']->find_by_student( $owner ) );
$sentinel = new RuntimeException( 'Roll back verification data.' );
try {
	TAAS_Transaction::run(
		static function () use ( &$period, $owner, $repos, $sentinel, $wpdb ) {
			while ( $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] )
				|| $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Fall, $period['year'] )
				|| $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Spring, $period['year'] )
				|| $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_C, $period['year'] ) ) {
				--$period['year'];
			}
			taas_test_set_period( $period );
			$professor = (int) $repos['professors']->insert( new TAAS_Professor( null, null, 'Edit verification', 'edit-professor@example.invalid' ) );
			$ids = array();
			foreach ( array( 'A', 'B', 'C' ) as $code ) {
				$ids[ $code ] = (string) $repos['courses']->insert( new TAAS_Course( null, 'TEST' . $code, 'Course ' . $code, $professor, TAAS_Semester::Summer_A, $period['year'] ) );
			}
			$body = array(
				'term'               => array(
					'semester' => $period['semester'],
					'year'     => $period['year'],
				),
				'details'            => array(
					'student_name'        => 'Edit verification',
					'student_email'       => TAAS_Auth::current_user()['email'],
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
				'course_preferences' => array(
					array(
						'course_id'            => $ids['A'],
						'rank'                 => 1,
						'has_completed_course' => true,
						'has_ta_for_course'    => true,
					),
					array(
						'course_id'            => $ids['B'],
						'rank'                 => 2,
						'has_completed_course' => true,
						'has_ta_for_course'    => true,
					),
				),
			);
			taas_edit_check( 201 === taas_edit_request( 'POST', '/student/application', $body )->get_status(), 'Fixture initial application created' );
			$exam_prefill = taas_edit_request( 'GET', '/student/application' )->get_data()['english_test_score'];
			taas_edit_check( 2 === $exam_prefill, 'Initial REST submission preserves the score category for prefilling' );
			$old_term = array(
				'student_name' => 'Prior term',
				'profile'      => array( 'available_hours' => 5 ),
			);
			$repos['submissions']->save_for_term( $owner, TAAS_Semester::Spring, $period['year'], $old_term );
			// Professor summaries must use the course's snapshot despite newer shared values.
			$repos['students']->save_identity( new TAAS_Student( $owner, 'Newer shared name', 'newer@ufl.edu' ) );
			$wpdb->update(
				$repos['profiles']->table(),
				array(
					'major'             => 'Newer major',
					'gpa'               => 4,
					'enrollment_status' => 3,
				),
				array( 'student_ufid' => $owner )
			);
			$applicants = $repos['applications']->applicants_for_course( (int) $ids['A'] );
			taas_edit_check(
				1 === count( $applicants ) && 'Edit verification' === $applicants[0]['student_name']
				&& 'CS' === $applicants[0]['major'] && 3.5 === $applicants[0]['gpa']
				&& TAAS_Enrollment_Status::Masters === $applicants[0]['enrollment_status'],
				'Professor applicant list uses the course-term snapshot rather than another term or the current profile'
			);
			$saved_details = $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] )->submitted_details;
			$no_gpa = $saved_details;
			$no_gpa['academic_background'][0]['gpa'] = null;
			$no_gpa['academic_background'][] = array(
				'major' => 'Previous major',
				'gpa'   => 3.9,
			);
			$repos['submissions']->save_for_term( $owner, TAAS_Semester::Summer_A, $period['year'], $no_gpa );
			taas_edit_check( null === $repos['applications']->applicants_for_course( (int) $ids['A'] )[0]['gpa'], 'Professor summary preserves an unavailable first-degree GPA without using a previous degree' );
			$repos['submissions']->save_for_term( $owner, TAAS_Semester::Summer_A, $period['year'], $saved_details );
			$repos['students']->save_identity( new TAAS_Student( $owner, $body['details']['student_name'], $body['details']['student_email'] ) );
			$wpdb->update(
				$repos['profiles']->table(),
				array(
					'major'             => 'CS',
					'gpa'               => 3.5,
					'enrollment_status' => 2,
				),
				array( 'student_ufid' => $owner )
			);
			$empty = taas_edit_request( 'GET', '/student/application/2/' . $period['year'] );
			taas_edit_check(
				200 === $empty->get_status() && array() === $empty->get_data()['course_preferences']
				&& $old_term == $empty->get_data()['details'],
				'Snapshot without course rows remains readable'
			);
			$other = '99996000';
			while ( $repos['students']->find( $other ) ) {
				$other = (string) ( (int) $other - 1 );
			}
			$repos['students']->insert( new TAAS_Student( $other, 'Another student', 'other@example.invalid' ) );
			$orphan_id = $repos['applications']->insert( new TAAS_Application( null, $other, (int) $ids['A'] ) );
			try {
				$repos['applications']->applicants_for_course( (int) $ids['A'] );
				throw new LogicException( 'Missing snapshot was silently hidden or replaced with a shared profile.' );
			} catch ( RuntimeException $error ) {
				taas_edit_check( 'An applicant is missing their term submission snapshot.' === $error->getMessage(), 'Missing professor snapshot raises an explicit integrity error' );
			} finally {
				$repos['applications']->delete( $orphan_id );
			}
			$foreign_id = $repos['submissions']->save_for_term( $other, TAAS_Semester::Summer_C, $period['year'], array( 'student_name' => 'Another student' ) );
			$terms = taas_edit_request( 'GET', '/student/application/terms' )->get_data()['terms'];
			taas_edit_check( ! in_array( $foreign_id, array_column( $terms, 'submission_id' ), true ), 'Term list excludes other students' );
			$route = '/student/application/3/' . $period['year'];
			$before_snapshot = $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$before_choices = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$interleaved = false;
			$hook = null;
			$hook = static function ( $sql ) use ( &$hook, &$interleaved, $repos, $owner, $period, $before_snapshot, $before_choices ) {
				if ( ! str_contains( $sql, 'SELECT a.* FROM' ) && ! str_contains( $sql, 'SELECT s.submission_id' ) ) {
					return $sql; }
				remove_filter( 'query', $hook );
				$interleaved = true;
				$changed = $before_snapshot->submitted_details;
				$changed['profile']['available_hours'] = 20;
				$repos['submissions']->save_for_term( $owner, TAAS_Semester::Summer_A, $period['year'], $changed );
				foreach ( $before_choices as $choice ) {
					$repos['applications']->save_student_choice( $owner, (int) $choice['application_id'], 3 - (int) $choice['student_preference_rank'], TAAS_Match_Status::Pending );
				}
				return $sql;
			};
			add_filter( 'query', $hook );
			try {
				$mixed = taas_edit_request( 'GET', $route )->get_data();
			} finally {
				remove_filter( 'query', $hook ); }
			$fresh = taas_edit_request( 'GET', $route )->get_data();
			taas_edit_check(
				$interleaved && 20 === $mixed['details']['profile']['available_hours'] && 20 === $fresh['details']['profile']['available_hours']
				&& $mixed['course_preferences'] === $fresh['course_preferences'] && $mixed['revision'] === $fresh['revision'],
				'Interleaved save returns consistent details, choices and revision'
			);
			$repos['submissions']->save_for_term( $owner, TAAS_Semester::Summer_A, $period['year'], $before_snapshot->submitted_details );
			foreach ( $before_choices as $choice ) {
				$repos['applications']->save_student_choice( $owner, (int) $choice['application_id'], (int) $choice['student_preference_rank'], TAAS_Match_Status::Pending );
			}
			$initial = taas_edit_request( 'GET', $route )->get_data();
			taas_edit_check( $initial['can_edit'] && count( $initial['courses'] ) >= 3, 'Open term read provides edit permission and real course choices' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$original_ids = array_column( $rows, 'application_id', 'applied_course_id' );
			$wpdb->update( $repos['applications']->table(), array( 'professor_preference_rank' => 2 ), array( 'application_id' => $original_ids[ $ids['B'] ] ) );
			$read = taas_edit_request( 'GET', $route )->get_data();
			$replacement = taas_edit_request( 'GET', '/student/application' )->get_data()['replacement'];
			taas_edit_check(
				$read['revision'] === $replacement['revision']
				&& add_query_arg( 'taas_role', 'student', rest_url( 'taas/v1' . $route ) ) === $replacement['endpoint']
				&& $read['details']['student_name'] === $replacement['student_name']
				&& $read['course_preferences'] === $replacement['course_preferences'],
				'Replacement loads the saved name and course preferences with their current revision'
			);
			$edit = $body;
			$edit['revision'] = $replacement['revision'];
			$forged_email = $edit;
			$forged_email['details']['student_email'] = 'professor@ufl.edu';
			foreach ( array( 'POST', 'PATCH', 'PUT' ) as $method ) {
				$rejected = taas_edit_request( $method, $route, $forged_email );
				taas_edit_check( 400 === $rejected->get_status() && 'taas_student_email_mismatch' === $rejected->get_data()['code'], $method . ' edit rejects another university email' );
			}
			taas_edit_check( $read['revision'] === taas_edit_request( 'GET', $route )->get_data()['revision'], 'Rejected email edits preserve the saved application' );
			foreach ( array( 'english_test_score' ) as $score_key ) {
				$invalid = $edit;
				$invalid['details']['profile'][ $score_key ] = null;
				taas_edit_check( 400 === taas_edit_request( 'POST', $route, $invalid )->get_status(), 'REST edits cannot clear ' . $score_key );
			}
			taas_edit_check( $read['revision'] === taas_edit_request( 'GET', $route )->get_data()['revision'], 'Rejected score edits preserve the saved application' );
			$edit['details']['profile']['available_hours'] = 20;
			$edit['details']['profile']['english_test_score'] = 3;
			$edit['details']['profile']['eap5837_status'] = 4;
			$edit['details']['profile']['enrollment_status'] = 1;
			$edit['details']['profile']['fws_eligibility'] = 'not_applicable';
			$edit['course_preferences'] = array(
				array(
					'course_id'            => $ids['B'],
					'rank'                 => 1,
					'has_completed_course' => false,
					'has_ta_for_course'    => false,
				),
				array(
					'course_id'            => $ids['C'],
					'rank'                 => 2,
					'has_completed_course' => true,
					'course_completion'    => 2,
					'has_ta_for_course'    => true,
				),
			);
			$invalid_country = $edit;
			$invalid_country['details']['profile']['country_code'] = 'ZZ';
			taas_edit_check( 400 === taas_edit_request( 'POST', $route, $invalid_country )->get_status(), 'Invalid country is rejected on edits too' );
			$_GET['taas_role'] = 'instructor';
			taas_edit_check( 403 === taas_edit_request( 'POST', $route, $edit )->get_status(), 'Non-student cannot edit' );
			$_GET['taas_role'] = 'student';
			$replacement_edit = $edit + array( 'replace_existing' => true );
			$updated = taas_edit_request( 'POST', $route, $replacement_edit );
			taas_edit_check( 200 === $updated->get_status(), 'Replacement saves reorder, removal, addition and details together' );
			$headers = array_change_key_case( $updated->get_headers(), CASE_LOWER );
			taas_edit_check( 'no-store' === ( $headers['cache-control'] ?? null ), 'Successful edit response prevents caching' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$by_id = array_column( $rows, null, 'applied_course_id' );
			taas_edit_check( 2 === count( $by_id ) && ! isset( $by_id[ $ids['A'] ] ), 'Replacement deletes the omitted course application' );
			$removed_id = $by_id[ $ids['C'] ]['application_id'];
			taas_edit_check( (int) $by_id[ $ids['B'] ]['student_preference_rank'] === 1 && (int) $by_id[ $ids['B'] ]['professor_preference_rank'] === 2, 'Student ranking update preserves professor ranking' );
			taas_edit_check( (int) $by_id[ $ids['C'] ]['match_status'] === 1, 'Added course starts pending' );
			taas_edit_check( (int) $by_id[ $ids['B'] ]['has_ta_for_course'] === 0 && (int) $by_id[ $ids['C'] ]['has_ta_for_course'] === 1, 'Replacement stores TA declarations on the selected courses' );
			$replaced = taas_edit_request( 'GET', $route )->get_data();
			taas_edit_check(
				array( $ids['B'], $ids['C'] ) === array_column( $replaced['course_preferences'], 'course_id' ),
				'Replacement status shows only selected courses'
			);
			$completion = array_column( $replaced['course_preferences'], 'course_completion', 'course_id' );
			taas_edit_check( 0 === $completion[ $ids['B'] ] && 2 === $completion[ $ids['C'] ], 'Replacement saves No and Equivalent' );
			$returned = array_column( taas_edit_request( 'GET', $route )->get_data()['course_preferences'], 'has_ta_for_course', 'course_id' );
			taas_edit_check( false === $returned[ $ids['B'] ] && true === $returned[ $ids['C'] ], 'Saved course TA declarations are returned for editing' );
			taas_edit_check( $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] )->submitted_details['profile']['available_hours'] === 20, 'Same-term snapshot is replaced' );
			taas_edit_check( TAAS_Enrollment_Status::Undergraduate === $repos['profiles']->find( $owner )->enrollment_status && 1 === taas_edit_request( 'GET', $route )->get_data()['details']['profile']['enrollment_status'], 'Undergraduate edits update both the shared profile and term snapshot' );
			$exam_profile = $repos['profiles']->find( $owner );
			$exam_snapshot = taas_edit_request( 'GET', $route )->get_data()['details']['profile'];
			taas_edit_check( TAAS_English_Test_Score::Upper_Range === $exam_profile->english_test_score && 3 === $exam_snapshot['english_test_score'], 'REST edits update the required category in the shared profile and snapshot' );
			taas_edit_check( TAAS_Course_Taken_Status::Not_Applicable === $exam_profile->eap5837_status && 4 === $exam_snapshot['eap5837_status'], 'N/A is saved for an EAP course in the shared profile and snapshot' );
			taas_edit_check( $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Spring, $period['year'] )->submitted_details == $old_term, 'Other term snapshot is preserved' );
			taas_edit_check( 409 === taas_edit_request( 'POST', $route, $replacement_edit )->get_status(), 'Stale replacement cannot overwrite newer saved edits' );
			$withdraw = $edit;
			$withdraw['revision'] = taas_edit_request( 'GET', $route )->get_data()['revision'];
			$withdraw['course_preferences'] = array();
			taas_edit_check( 400 === taas_edit_request( 'POST', $route, $withdraw )->get_status(), 'An ordinary edit requires at least one course' );
			taas_edit_check( $withdraw['revision'] === taas_edit_request( 'GET', $route )->get_data()['revision'], 'Rejecting an empty selection preserves the application' );
			$reactivate = $edit;
			$reactivate['revision'] = taas_edit_request( 'GET', $route )->get_data()['revision'];
			$reactivate['course_preferences'] = array(
				array(
					'course_id'            => $ids['C'],
					'rank'                 => 1,
					'has_completed_course' => true,
				),
				array(
					'course_id'            => $ids['A'],
					'rank'                 => 2,
					'has_completed_course' => true,
				),
			);
			taas_edit_check( 200 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'An edit can re-add removed courses' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$by_id = array_column( $rows, null, 'applied_course_id' );
			taas_edit_check( $by_id[ $ids['C'] ]['application_id'] === $removed_id && (int) $by_id[ $ids['C'] ]['match_status'] === 1, 'A retained course keeps its pending row' );
			taas_edit_check( $by_id[ $ids['A'] ]['application_id'] !== $original_ids[ $ids['A'] ], 'Re-adding a course deleted by replacement creates a new row' );
			taas_edit_check( 0 === (int) $by_id[ $ids['C'] ]['has_ta_for_course'], 'Reactivation stores the newly submitted TA declaration' );
			$retained_id = $by_id[ $ids['A'] ]['application_id'];
			$reactivate['revision'] = taas_edit_request( 'GET', $route )->get_data()['revision'];
			$rejoin = $reactivate;
			$rejoin['course_preferences'][0]['course_id'] = $ids['B'];
			taas_edit_check( 200 === taas_edit_request( 'POST', $route, $rejoin )->get_status(), 'Students can rejoin a withdrawn course during the student application stage' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$by_id = array_column( $rows, null, 'applied_course_id' );
			taas_edit_check( ! isset( $by_id[ $ids['C'] ] ) && $retained_id === $by_id[ $ids['A'] ]['application_id'], 'A regular edit deletes the removed course and preserves the retained row' );
			$reactivate['revision'] = taas_edit_request( 'GET', $route )->get_data()['revision'];
			taas_edit_check( 200 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'A student can re-add a course after removing it' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$by_id = array_column( $rows, null, 'applied_course_id' );
			taas_edit_check( $by_id[ $ids['C'] ]['application_id'] !== $removed_id && (int) $by_id[ $ids['C'] ]['match_status'] === 1, 'Re-adding a removed course creates a new pending row' );
			$wrong_term = $reactivate;
			$wrong_term['term']['year']--;
			taas_edit_check( 400 === taas_edit_request( 'POST', $route, $wrong_term )->get_status(), 'Payload cannot target a different term from the route' );
			taas_edit_check(
				404 === taas_edit_request(
					'POST',
					'/student/application/5/' . $period['year'],
					array_merge(
						$reactivate,
						array(
							'term' => array(
								'semester' => 5,
								'year'     => $period['year'],
							),
						)
					)
				)->get_status(),
				'Another student snapshot cannot be edited'
			);
			taas_test_set_period( array( 'is_open' => false ) + $period );
			taas_edit_check( 403 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'Administrator closure blocks edits on the server' );
			taas_edit_check( null === taas_edit_request( 'GET', '/student/application' )->get_data()['replacement'], 'Closed applications do not offer replacement' );
			$closed_read = taas_edit_request( 'GET', $route );
			taas_edit_check( 200 === $closed_read->get_status() && false === $closed_read->get_data()['can_edit'], 'Saved application stays readable, not editable, after closure' );
			taas_edit_check( 200 === taas_edit_request( 'GET', '/student/application/terms' )->get_status(), 'Term list stays readable after closure' );
			taas_test_set_period( array( 'closes_at' => time() - 1 ) + $period );
			taas_edit_check( 403 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'Passed deadline blocks edits without grace' );
			taas_test_set_period( $period );
			$wpdb->update( $repos['applications']->table(), array( 'match_status' => 2 ), array( 'application_id' => $retained_id ) );
			taas_edit_check( null === taas_edit_request( 'GET', '/student/application' )->get_data()['replacement'], 'Matched applications do not offer replacement' );
			taas_edit_check( ! taas_edit_request( 'GET', $route )->get_data()['can_edit'] && 403 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'Deferred matched-application policy is not bypassed' );

			$period['semester'] = TAAS_Semester::Fall->value;
			taas_test_set_period( $period );
			$phd = $body;
			$phd['term']['semester'] = $period['semester'];
			$phd['details']['profile']['enrollment_status'] = TAAS_Enrollment_Status::Phd->value;
			$phd['course_preferences'] = array();
			$phd_ids = array();
			foreach ( range( 1, 8 ) as $rank ) {
				$id = (string) $repos['courses']->insert( new TAAS_Course( null, 'PHD' . $rank, 'PhD test course', $professor, TAAS_Semester::Fall, $period['year'] ) );
				$phd_ids[ $rank ] = $id;
				if ( $rank <= 5 ) {
					$phd['course_preferences'][] = array(
						'course_id'            => $id,
						'rank'                 => $rank,
						'has_completed_course' => true,
					);
				}
			}
			$phd_route = '/student/application/' . $period['semester'] . '/' . $period['year'];
			$read_phd = static fn() => taas_edit_request( 'GET', $phd_route )->get_data();
			taas_edit_check( 201 === taas_edit_request( 'POST', '/student/application', $phd )->get_status(), 'PhD application is created from five ranked choices' );
			taas_edit_check( 200 === taas_edit_request( 'POST', '/student/application', $phd )->get_status(), 'PhD creation retries recognize the automatically added rows' );
			$saved_phd = $read_phd();
			taas_edit_check( 'N/A' === $saved_phd['details']['profile']['advisor_name'], 'An omitted advisor is saved as N/A in the term application' );
			$choices = array_column( $saved_phd['course_preferences'], null, 'course_id' );
			taas_edit_check( 8 === count( $choices ) && 5 === $choices[ $phd_ids[5] ]['rank'], 'PhD submission saves all eight courses with the selected ranks' );
			$extra = $choices[ $phd_ids[6] ];
			taas_edit_check( null === $extra['rank'] && 1 === $extra['match_status'] && 0 === $extra['course_completion'] && false === $extra['has_ta_for_course'], 'Automatically added courses are pending with a null rank and default history' );

			$phd_edit = $phd;
			$phd_edit['details']['profile']['advisor_name'] = 'Dr. Morgan Rivera';
			$phd_edit['revision'] = $saved_phd['revision'];
			$demoted = $choices[ $phd_ids[5] ];
			$phd_edit['course_preferences'][4] = array(
				'course_id'            => $phd_ids[6],
				'rank'                 => 5,
				'has_completed_course' => true,
				'has_ta_for_course'    => true,
			);
			$invalid_phd_edit = $phd_edit;
			$invalid_phd_edit['course_preferences'][] = array(
				'course_id'            => $phd_ids[7],
				'rank'                 => null,
				'has_completed_course' => true,
			);
			taas_edit_check( 400 === taas_edit_request( 'POST', $phd_route, $invalid_phd_edit )->get_status(), 'PhD edits reject explicit unranked courses' );
			taas_edit_check( $saved_phd['revision'] === $read_phd()['revision'], 'Rejecting an explicit unranked course preserves the application' );
			taas_edit_check( 200 === taas_edit_request( 'POST', $phd_route, $phd_edit )->get_status(), 'PhD edits accept five ranked courses' );
			taas_edit_check( 'Dr. Morgan Rivera' === $read_phd()['details']['profile']['advisor_name'], 'The advisor name is saved and returned in application details' );
			taas_edit_check( 'Dr. Morgan Rivera' === taas_edit_request( 'GET', '/student/application' )->get_data()['advisor_name'], 'The application form prefills the saved advisor name' );
			$choices = array_column( $read_phd()['course_preferences'], null, 'course_id' );
			$demoted['rank'] = null;
			taas_edit_check( $demoted === $choices[ $phd_ids[5] ] && 5 === $choices[ $phd_ids[6] ]['rank'], 'A PhD course swap keeps the deselected row and clears only its rank' );
			taas_edit_check( 8 === count( $choices ) && null === $choices[ $phd_ids[8] ]['rank'] && true === $choices[ $phd_ids[6] ]['has_ta_for_course'], 'PhD edits retain omitted unranked courses and save answers for ranked courses' );
			$replacement_phd = taas_edit_request( 'GET', '/student/application' )->get_data()['replacement'];
			taas_edit_check( 5 === count( $replacement_phd['course_preferences'] ) && $replacement_phd['reset_prior_ta'], 'PhD replacement prefills only the five active ranks and resets prior TA answers' );
			$phd['revision'] = $replacement_phd['revision'];
			$phd['replace_existing'] = true;
			$phd['details']['profile']['advisor_name'] = '';
			taas_edit_check( 200 === taas_edit_request( 'POST', $phd_route, $phd )->get_status(), 'PhD replacement restores full course coverage' );
			taas_edit_check( 'N/A' === $read_phd()['details']['profile']['advisor_name'], 'Clearing the advisor name saves N/A' );
			$choices = array_column( $read_phd()['course_preferences'], null, 'course_id' );
			taas_edit_check( 1 === $choices[ $phd_ids[8] ]['match_status'] && false === $choices[ $phd_ids[6] ]['has_ta_for_course'], 'Replacement retains course coverage and resets unranked history' );

			unset( $phd['replace_existing'] );
			$masters = $phd;
			$masters['revision'] = $read_phd()['revision'];
			$masters['details']['profile']['enrollment_status'] = TAAS_Enrollment_Status::Masters->value;
			$masters['course_preferences'] = array_slice( $masters['course_preferences'], 0, 3 );
			taas_edit_check( 200 === taas_edit_request( 'POST', $phd_route, $masters )->get_status() && 3 === count( $read_phd()['course_preferences'] ), 'Switching away from PhD deletes omitted applications' );
			$phd['revision'] = $read_phd()['revision'];
			taas_edit_check( 200 === taas_edit_request( 'POST', $phd_route, $phd )->get_status() && 8 === count( $read_phd()['course_preferences'] ), 'Switching to PhD adds the remaining courses after five ranks are supplied' );
			$saved_phd = $read_phd();
			$withdraw_all = array(
				'term'         => $phd['term'],
				'revision'     => $saved_phd['revision'],
				'withdraw_all' => true,
			);
			taas_edit_check( 400 === taas_edit_request( 'POST', $phd_route, $withdraw_all )->get_status(), 'The retired bulk withdrawal request is rejected' );
			$phd['revision'] = $saved_phd['revision'];
			$phd['course_preferences'] = array();
			taas_edit_check( 400 === taas_edit_request( 'POST', $phd_route, $phd )->get_status(), 'A PhD edit cannot save an empty selection' );
			taas_edit_check( $saved_phd['revision'] === $read_phd()['revision'], 'Rejected PhD requests preserve the application' );
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
taas_edit_check( $before === array_map( static fn( TAAS_Repository $repo ) => $repo->count(), $repos ), 'All edit-verification records rolled back' );
taas_edit_check( $old_student === $repos['students']->find( $owner )?->to_row() && $old_profile === $repos['profiles']->find( $owner )?->to_row(), 'Existing student identity and profile preserved' );
taas_edit_check( $old_degrees === array_map( static fn( TAAS_Academic_Background $entry ) => $entry->to_row(), $repos['backgrounds']->find_by_student( $owner ) ), 'Existing academic background preserved' );
