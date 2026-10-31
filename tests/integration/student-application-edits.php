<?php
/**
 * Student-owned application editing checks against local WordPress/MySQL.
 *
 * Run: docker compose exec -T wordpress php /var/www/taas/tests/integration/student-application-edits.php
 * All fixtures and changes to existing shared data are rolled back.
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

/** Assert a storage or access guarantee. */
function taas_edit_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

/** Dispatch a registered route, including permission checks. */
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
$period = array( 'semester' => 3, 'year' => 2150, 'is_open' => true, 'closes_at' => time() + 3600 );
$filter = static function () use ( &$period ) { return $period; };
add_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $filter );
$repos = array(
	'students' => new TAAS_Student_Repository(), 'profiles' => new TAAS_Student_Profile_Repository(),
	'backgrounds' => new TAAS_Academic_Background_Repository(), 'applications' => new TAAS_Application_Repository(),
	'submissions' => new TAAS_Student_Submission_Repository(), 'courses' => new TAAS_Course_Repository(),
	'professors' => new TAAS_Professor_Repository(),
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
				|| $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Spring, $period['year'] )
				|| $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_C, $period['year'] ) ) {
				--$period['year'];
			}
			$professor = '99997000';
			while ( $repos['professors']->find( $professor ) ) {
				$professor = (string) ( (int) $professor - 1 );
			}
			$repos['professors']->insert( new TAAS_Professor( $professor, 'Edit verification', 'edit-professor@example.invalid' ) );
			$ids = array();
			foreach ( array( 'A', 'B', 'C' ) as $code ) {
				$id = 'edit-' . bin2hex( random_bytes( 5 ) );
				$ids[ $code ] = $id;
				$repos['courses']->insert( new TAAS_Course( $id, 'TEST' . $code, 'Course ' . $code, $professor, TAAS_Semester::Summer_A, $period['year'] ) );
			}
			$body = array(
				'term' => array( 'semester' => $period['semester'], 'year' => $period['year'] ),
				'details' => array(
					'student_name' => 'Edit verification', 'student_email' => 'edit.student@ufl.edu',
					'profile' => array( 'phone' => '3525550100', 'country_code' => 'US', 'admitted_semester' => 'Fall 2025', 'available_hours' => 10, 'enrollment_status' => 3, 'eap5836_status' => 1, 'eap5837_status' => 4 ),
					'academic_background' => array( array( 'university' => 'Example University', 'degree_type' => 2, 'major' => 'CS', 'grad_date' => '2025-05-01', 'gpa' => 3.5 ) ),
				),
				'course_preferences' => array(
					array( 'course_id' => $ids['A'], 'rank' => 1, 'has_completed_course' => true ),
					array( 'course_id' => $ids['B'], 'rank' => 2, 'has_completed_course' => true ),
				),
			);
			taas_edit_check( 201 === taas_edit_request( 'POST', '/student/application', $body )->get_status(), 'Fixture initial application created' );
			$old_term = array( 'student_name' => 'Prior term', 'profile' => array( 'available_hours' => 5 ) );
			$repos['submissions']->save_for_term( $owner, TAAS_Semester::Spring, $period['year'], $old_term );
			$other = '99996000';
			while ( $repos['students']->find( $other ) ) {
				$other = (string) ( (int) $other - 1 );
			}
			$repos['students']->insert( new TAAS_Student( $other, 'Another student', 'other@example.invalid' ) );
			$foreign_id = $repos['submissions']->save_for_term( $other, TAAS_Semester::Summer_C, $period['year'], array( 'student_name' => 'Another student' ) );
			$terms = taas_edit_request( 'GET', '/student/applications' )->get_data()['terms'];
			taas_edit_check( ! in_array( $foreign_id, array_column( $terms, 'submission_id' ), true ), 'Term list excludes other students' );
			$route = '/student/applications/3/' . $period['year'];
			$initial = taas_edit_request( 'GET', $route )->get_data();
			taas_edit_check( $initial['can_edit'] && count( $initial['courses'] ) >= 3, 'Open term read provides edit permission and real course choices' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$original_ids = array_column( $rows, 'application_id', 'applied_course_id' );
			$wpdb->update( $repos['applications']->table(), array( 'professor_preference_rank' => 2 ), array( 'application_id' => $original_ids[ $ids['B'] ] ) );
			$read = taas_edit_request( 'GET', $route )->get_data();
			$edit = $body;
			$edit['revision'] = $read['revision'];
			$edit['details']['profile']['available_hours'] = 20;
			$edit['course_preferences'] = array(
				array( 'course_id' => $ids['B'], 'rank' => 1, 'has_completed_course' => true ),
				array( 'course_id' => $ids['C'], 'rank' => 2, 'has_completed_course' => true ),
			);
			$_GET['taas_role'] = 'instructor';
			taas_edit_check( 403 === taas_edit_request( 'POST', $route, $edit )->get_status(), 'Non-student cannot edit' );
			$_GET['taas_role'] = 'student';
			taas_edit_check( 200 === taas_edit_request( 'POST', $route, $edit )->get_status(), 'Reorder, removal, addition and detail changes save together' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$by_id = array_column( $rows, null, 'applied_course_id' );
			taas_edit_check( (int) $by_id[ $ids['A'] ]['match_status'] === 4 && $by_id[ $ids['A'] ]['application_id'] === $original_ids[ $ids['A'] ], 'Removed pending course is retained as withdrawn' );
			taas_edit_check( (int) $by_id[ $ids['B'] ]['student_preference_rank'] === 1 && (int) $by_id[ $ids['B'] ]['professor_preference_rank'] === 2, 'Student ranking update preserves professor ranking' );
			taas_edit_check( (int) $by_id[ $ids['C'] ]['match_status'] === 1, 'Added course starts pending' );
			taas_edit_check( $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] )->submitted_details['profile']['available_hours'] === 20, 'Same-term snapshot is replaced' );
			taas_edit_check( $repos['submissions']->find_by_student_and_term( $owner, TAAS_Semester::Spring, $period['year'] )->submitted_details == $old_term, 'Other term snapshot is preserved' );
			taas_edit_check( 409 === taas_edit_request( 'POST', $route, $edit )->get_status(), 'Stale loaded version cannot overwrite newer saved edits' );
			$withdraw = $edit;
			$withdraw['revision'] = taas_edit_request( 'GET', $route )->get_data()['revision'];
			$withdraw['course_preferences'] = array();
			taas_edit_check( 200 === taas_edit_request( 'POST', $route, $withdraw )->get_status(), 'Empty selection withdraws every course' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			taas_edit_check( count( $rows ) === 3 && count( array_filter( $rows, static fn( array $row ) => (int) $row['match_status'] === 4 ) ) === 3, 'Withdrawing all keeps all original records' );
			$reactivate = $edit;
			$reactivate['revision'] = taas_edit_request( 'GET', $route )->get_data()['revision'];
			$reactivate['course_preferences'] = array( array( 'course_id' => $ids['A'], 'rank' => 1, 'has_completed_course' => true ) );
			taas_edit_check( 200 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'Withdrawn course without professor rank can be reactivated' );
			$rows = $repos['applications']->find_by_student_and_term( $owner, TAAS_Semester::Summer_A, $period['year'] );
			$by_id = array_column( $rows, null, 'applied_course_id' );
			taas_edit_check( $by_id[ $ids['A'] ]['application_id'] === $original_ids[ $ids['A'] ] && (int) $by_id[ $ids['A'] ]['match_status'] === 1, 'Reactivation reuses the same course application ID' );
			$reactivate['revision'] = taas_edit_request( 'GET', $route )->get_data()['revision'];
			$blocked = $reactivate;
			$blocked['course_preferences'][0]['course_id'] = $ids['B'];
			taas_edit_check( 409 === taas_edit_request( 'POST', $route, $blocked )->get_status(), 'Unresolved prior professor-rank reactivation requires review' );
			$wrong_term = $reactivate;
			$wrong_term['term']['year']--;
			taas_edit_check( 400 === taas_edit_request( 'POST', $route, $wrong_term )->get_status(), 'Payload cannot target a different term from the route' );
			taas_edit_check( 404 === taas_edit_request( 'POST', '/student/applications/5/' . $period['year'], array_merge( $reactivate, array( 'term' => array( 'semester' => 5, 'year' => $period['year'] ) ) ) )->get_status(), 'Another student snapshot cannot be edited' );
			$period['is_open'] = false;
			taas_edit_check( 403 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'Administrator closure blocks edits on the server' );
			$period['is_open'] = true;
			$period['closes_at'] = time() - 1;
			taas_edit_check( 403 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'Passed deadline blocks edits without grace' );
			$period['closes_at'] = time() + 3600;
			$wpdb->update( $repos['applications']->table(), array( 'match_status' => 2 ), array( 'application_id' => $original_ids[ $ids['A'] ] ) );
			taas_edit_check( ! taas_edit_request( 'GET', $route )->get_data()['can_edit'] && 403 === taas_edit_request( 'POST', $route, $reactivate )->get_status(), 'Deferred matched-application policy is not bypassed' );
			throw $sentinel;
		}
	);
} catch ( Throwable $error ) {
	if ( $error !== $sentinel ) {
		fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
} finally {
	remove_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $filter );
	$_GET = $original_get;
}
taas_edit_check( $before === array_map( static fn( TAAS_Repository $repo ) => $repo->count(), $repos ), 'All edit-verification records rolled back' );
taas_edit_check( $old_student === $repos['students']->find( $owner )?->to_row() && $old_profile === $repos['profiles']->find( $owner )?->to_row(), 'Existing student identity and profile preserved' );
taas_edit_check( $old_degrees === array_map( static fn( TAAS_Academic_Background $entry ) => $entry->to_row(), $repos['backgrounds']->find_by_student( $owner ) ), 'Existing academic background preserved' );
