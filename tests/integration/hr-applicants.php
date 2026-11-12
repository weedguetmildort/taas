<?php
/**
 * Local integration checks for the HR applicant list and details.
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
 * @param string   $role Local role override.
 * @param int|null $id   Submission ID for a detail request.
 */
function taas_hr_applicants_get( string $role, ?int $id = null ): WP_REST_Response {
	$_GET['taas_role'] = $role;
	$route   = '/taas/v1/hr/applicants' . ( null === $id ? '' : '/' . $id );
	$request = new WP_REST_Request( 'GET', $route );
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
				'degree_type' => TAAS_Degree_Type::BS->value,
				'gpa'         => 3.5,
			),
		),
		'profile'             => array(
			'country_code'         => 'US',
			'enrollment_status'    => TAAS_Enrollment_Status::Phd->value,
			'advisor_name'         => 'Dr. Example',
			'fws_eligibility'      => 'yes',
			'fws_award_letter_url' => 'https://example.invalid/fws-award',
		),
	);
	$spring_id = $submissions->save_for_term( $ufid, TAAS_Semester::Spring, 2026, $details + array( 'private_fixture' => 'Ignore extra snapshot fields' ) );
	$details['student_name'] = 'Submitted Fall name';
	$fall_id = $submissions->save_for_term( $ufid, TAAS_Semester::Fall, 2026, $details );
	$details['student_name'] = 'Submitted prior Spring name';
	$prior_spring_id = $submissions->save_for_term( $ufid, TAAS_Semester::Spring, 2025, $details );

	$cycles = new TAAS_Application_Cycle_Repository();
	$open   = array(
		'hr_review' => array(
			'start_at' => new DateTimeImmutable( '-1 day' ),
			'end_at'   => null,
		),
	);
	$cycles->save( new TAAS_Application_Cycle( TAAS_Semester::Spring, 2026, $open ) );
	$expected = array(
		'submission_id'      => (string) $spring_id,
		'name'               => 'Submitted Spring name',
		'student_type'       => 'phd',
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
		taas_hr_applicants_check( in_array( $expected, $data['applicants'], true ), 'Expected the summary to classify the PhD student by enrollment, even with a BS in academic history.' );

		$detail = taas_hr_applicants_get( $role, $spring_id );
		$saved  = $detail->get_data()['details'] ?? array();
		taas_hr_applicants_check( 200 === $detail->get_status() && 'Submitted Spring name' === $saved['student_name'], 'Expected the selected term snapshot.' );
		taas_hr_applicants_check( 'hr-fixture@example.invalid' === $saved['student_email'] && 'US' === $saved['profile']->country_code && 3.5 === $saved['academic_background'][0]['gpa'], 'Expected submitted contact and academic fields.' );
		taas_hr_applicants_check( 'Dr. Example' === $saved['profile']->advisor_name, 'Expected the PhD advisor from the term snapshot.' );
		taas_hr_applicants_check( ! property_exists( $saved['profile'], 'fws_eligibility' ) && ! property_exists( $saved['profile'], 'fws_award_letter_url' ), 'PhD applicants must not expose FWS fields.' );
		taas_hr_applicants_check( ! isset( $saved['private_fixture'] ) && 'private, no-store' === $detail->get_headers()['Cache-Control'], 'Details must use an explicit field list and disable caching.' );
		foreach ( array( $fall_id, $prior_spring_id, PHP_INT_MAX ) as $out_of_scope ) {
			taas_hr_applicants_check( 404 === taas_hr_applicants_get( $role, $out_of_scope )->get_status(), 'Missing and other-term submissions must return 404.' );
		}
	}
	taas_hr_applicants_check( 403 === taas_hr_applicants_get( 'student' )->get_status(), 'Students cannot read the HR list.' );
	taas_hr_applicants_check( 403 === taas_hr_applicants_get( 'student', $spring_id )->get_status(), 'Students cannot read HR details.' );

	$details['profile']['enrollment_status'] = TAAS_Enrollment_Status::Undergraduate->value;
	$submissions->save_for_term( $ufid, TAAS_Semester::Spring, 2026, $details );
	$undergraduate = taas_hr_applicants_get( 'hr', $spring_id )->get_data()['details']['profile'];
	taas_hr_applicants_check( 'yes' === $undergraduate->fws_eligibility && 'https://example.invalid/fws-award' === $undergraduate->fws_award_letter_url, 'Undergraduate details must include the submitted FWS information.' );
	$details['profile']['enrollment_status'] = TAAS_Enrollment_Status::Masters->value;
	$submissions->save_for_term( $ufid, TAAS_Semester::Spring, 2026, $details );
	$masters = taas_hr_applicants_get( 'hr', $spring_id )->get_data()['details']['profile'];
	taas_hr_applicants_check( ! property_exists( $masters, 'fws_eligibility' ) && ! property_exists( $masters, 'fws_award_letter_url' ), 'Master’s applicants must not expose FWS fields.' );

	$cycles->save( new TAAS_Application_Cycle( TAAS_Semester::Spring, 2026, array() ) );
	foreach ( array( 'hr', 'admin' ) as $role ) {
		$response = taas_hr_applicants_get( $role );
		taas_hr_applicants_check( 403 === $response->get_status() && 'taas_stage_closed' === $response->get_data()['code'], 'Closed HR review must refuse list requests.' );
		$detail = taas_hr_applicants_get( $role, $spring_id );
		taas_hr_applicants_check( 403 === $detail->get_status() && 'taas_stage_closed' === $detail->get_data()['code'], 'Closed HR review must refuse detail requests.' );
	}
	echo 'PASS: HR role and stage access, term isolation, and applicant summary and detail fields.' . PHP_EOL;
} finally {
	$wpdb->query( 'ROLLBACK' );
}
