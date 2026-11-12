<?php
/**
 * Local integration checks for the HR applicant list.
 * Run the script inside the local WordPress container.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.GlobalVariablesOverride -- CLI fixtures share a rolled-back transaction and use the local mock role.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development required.' );
}

/**
 * Fail when an endpoint guarantee is broken.
 *
 * @param bool   $condition Expected result.
 * @param string $message   Diagnostic message.
 * @throws RuntimeException When the condition fails.
 */
function taas_hr_applicants_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion message.
		throw new RuntimeException( $message );
	}
}

/**
 * Dispatch as the requested local mock role.
 *
 * @param string $role Local role override.
 */
function taas_hr_applicants_get( string $role ): WP_REST_Response {
	$_GET['taas_role'] = $role;
	$request = new WP_REST_Request( 'GET', '/taas/v1/hr/applicants' );
	$request->set_query_params(
		array(
			'term' => 'Fall',
			'year' => 2026,
		)
	);
	return rest_do_request( $request );
}

global $wpdb;
$students = new TAAS_Student_Repository();
$ufid     = '99999015';
taas_hr_applicants_check( null === $students->find( $ufid ), 'Fixture UFID already exists.' );
taas_hr_applicants_check( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not start the test transaction.' );
try {
	$students->insert( new TAAS_Student( $ufid, 'Current shared name', 'hr-fixture@example.invalid' ) );
	$submissions = new TAAS_Student_Submission_Repository();
	$details     = array(
		'student_name'        => 'Submitted Spring name',
		'student_email'       => 'hr-fixture@example.invalid',
		'academic_background' => array(
			array(
				'degree_type' => TAAS_Degree_Type::MS->value,
				'gpa'         => 3.5,
			),
		),
		'profile'             => array( 'country_code' => 'US' ),
	);
	$submissions->save_for_term( $ufid, TAAS_Semester::Spring, 2026, $details );
	$details['student_name'] = 'Submitted Fall name';
	$submissions->save_for_term( $ufid, TAAS_Semester::Fall, 2026, $details );
	$details['student_name'] = 'Submitted prior Spring name';
	$submissions->save_for_term( $ufid, TAAS_Semester::Spring, 2025, $details );

	$cycles = new TAAS_Application_Cycle_Repository();
	$open   = array(
		'hr_review' => array(
			'start_at' => new DateTimeImmutable( '-1 day' ),
			'end_at'   => null,
		),
	);
	$cycles->save( new TAAS_Application_Cycle( TAAS_Semester::Spring, 2026, $open ) );
	$expected = array(
		'name'               => 'Submitted Spring name',
		'student_type'       => 'masters',
		'gpa'                => 3.5,
		'english_test_score' => null,
		'country_code'       => 'US',
	);
	foreach ( array( 'hr', 'admin' ) as $role ) {
		$response = taas_hr_applicants_get( $role );
		$data     = $response->get_data();
		taas_hr_applicants_check( 200 === $response->get_status() && 'Spring' === $data['term'] && 2026 === $data['year'], 'Expected the active Spring term.' );
		$names = array_column( $data['applicants'], 'name' );
		taas_hr_applicants_check( ! in_array( 'Submitted Fall name', $names, true ) && ! in_array( 'Submitted prior Spring name', $names, true ), 'Another term was returned.' );
		taas_hr_applicants_check( in_array( $expected, $data['applicants'], true ), 'Expected only the submitted summary fields.' );
	}
	taas_hr_applicants_check( 403 === taas_hr_applicants_get( 'student' )->get_status(), 'Students cannot read the HR list.' );

	$cycles->save( new TAAS_Application_Cycle( TAAS_Semester::Spring, 2026, array() ) );
	foreach ( array( 'hr', 'admin' ) as $role ) {
		$response = taas_hr_applicants_get( $role );
		taas_hr_applicants_check( 403 === $response->get_status() && 'taas_stage_closed' === $response->get_data()['code'], 'Closed HR review must refuse list requests.' );
	}
	echo 'PASS: HR role and stage access, term isolation, and summary fields.' . PHP_EOL;
} finally {
	$wpdb->query( 'ROLLBACK' );
}
