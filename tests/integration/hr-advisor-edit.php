<?php
/**
 * Verify HR advisor corrections against the local WordPress database.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.GlobalVariablesOverride -- Local mock roles exercise REST authorization.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development required.' );
}

/**
 * Assert an endpoint guarantee.
 *
 * @param bool   $condition Expected result.
 * @param string $message Failure description.
 * @throws RuntimeException When the assertion fails.
 */
function taas_advisor_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI diagnostic.
		throw new RuntimeException( $message );
	}
}

/**
 * Dispatch a local HR advisor correction.
 *
 * @param string $role Local role override.
 * @param int    $id Submission ID.
 * @param array  $body JSON body.
 * @return WP_REST_Response
 */
function taas_advisor_save( string $role, int $id, array $body ): WP_REST_Response {
	$_GET['taas_role'] = $role;
	$request = new WP_REST_Request( 'POST', '/taas/v1/hr/applicants/' . $id . '/advisor' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
}

try {
	TAAS_Transaction::run(
		static function () {
			$ufid = '99999017';
			$students = new TAAS_Student_Repository();
			taas_advisor_check( null === $students->find( $ufid ), 'Fixture already exists.' );
			$students->insert( new TAAS_Student( $ufid, 'Advisor fixture', 'advisor-fixture@example.invalid' ) );
			$profiles = new TAAS_Student_Profile_Repository();
			$profiles->insert( new TAAS_Student_Profile( $ufid, phone: '+15555555555', advisor_name: 'Dr. Original' ) );
			$submissions = new TAAS_Student_Submission_Repository();
			$details = array(
				'student_name'        => 'Advisor fixture',
				'profile'             => array(
					'enrollment_status' => 3,
					'advisor_name'      => 'Dr. Original',
					'country_code'      => 'US',
				),
				'academic_background' => array( array( 'major' => 'Computer Science' ) ),
			);
			$id = $submissions->save_for_term( $ufid, TAAS_Semester::Spring, 2026, $details );
			$other = $submissions->save_for_term( $ufid, TAAS_Semester::Fall, 2026, $details );
			$details = $submissions->find( $id )->submitted_details;
			$cycles = new TAAS_Application_Cycle_Repository();
			$cycles->save(
				new TAAS_Application_Cycle(
					TAAS_Semester::Spring,
					2026,
					array(
						'hr_review' => array(
							'start_at' => new DateTimeImmutable( '-1 day' ),
							'end_at'   => null,
						),
					)
				)
			);
			$body = array(
				'advisor_name'          => 'Dr. Updated',
				'advisor_email'         => 'updated@example.invalid',
				'expected_advisor_name' => 'Dr. Original',
			);
			taas_advisor_check( 403 === taas_advisor_save( 'student', $id, $body )->get_status(), 'Students must not edit advisor details.' );
			taas_advisor_check( 404 === taas_advisor_save( 'hr', $other, $body )->get_status(), 'Other terms must not be editable.' );
			taas_advisor_check(
				400 === taas_advisor_save(
					'hr',
					$id,
					array(
						'professor_id'          => '1',
						'expected_advisor_name' => 'Dr. Original',
					)
				)->get_status(),
				'Professor selection is no longer supported.'
			);
			$response = taas_advisor_save( 'hr', $id, $body );
			taas_advisor_check( 200 === $response->get_status() && 'Dr. Updated' === $response->get_data()['advisor_name'], 'HR must be able to correct an advisor name.' );
			$expected = $details;
			$expected['profile']['advisor_name'] = 'Dr. Updated';
			$expected['profile']['advisor_email'] = 'updated@example.invalid';
			$actual = $submissions->find( $id )->submitted_details;
			ksort( $expected['profile'] );
			ksort( $actual['profile'] );
			taas_advisor_check( $expected === $actual, 'Only the selected advisor fields may change.' );
			taas_advisor_check( 'Dr. Updated' === $profiles->find( $ufid )->advisor_name && '+15555555555' === $profiles->find( $ufid )->phone, 'The shared advisor must update while other profile fields remain intact.' );
			taas_advisor_check( $details === $submissions->find( $other )->submitted_details, 'Other term snapshots must remain intact.' );
			taas_advisor_check( 409 === taas_advisor_save( 'admin', $id, $body )->get_status(), 'Stale edits must return a conflict.' );
			taas_advisor_check( 'Dr. Updated' === $profiles->find( $ufid )->advisor_name, 'Conflicts must not change the shared advisor.' );
			foreach ( array( '', '   ', str_repeat( 'x', 256 ), "Invalid\nName", "Invalid\0Name", array() ) as $invalid_name ) {
				taas_advisor_check(
					400 === taas_advisor_save(
						'hr',
						$id,
						array(
							'advisor_name'          => $invalid_name,
							'expected_advisor_name' => 'Dr. Updated',
						)
					)->get_status(),
					'Invalid custom advisor names must be rejected.'
				);
			}
			$custom = array(
				'advisor_name'          => '  Dr. Custom Álvarez  ',
				'advisor_email'         => 'custom-advisor@example.invalid',
				'expected_advisor_name' => 'Dr. Updated',
			);
			foreach ( array( null, '', 'invalid-email', "bad\n@example.invalid", array() ) as $invalid_email ) {
				$invalid = $custom;
				$invalid['advisor_email'] = $invalid_email;
				taas_advisor_check( 400 === taas_advisor_save( 'hr', $id, $invalid )->get_status(), 'Custom advisors require a valid email address.' );
			}
			$response = taas_advisor_save( 'hr', $id, $custom );
			taas_advisor_check( 200 === $response->get_status() && 'Dr. Custom Álvarez' === $response->get_data()['advisor_name'], 'HR must be able to enter a custom advisor name.' );
			taas_advisor_check( 'Dr. Custom Álvarez' === $profiles->find( $ufid )->advisor_name && 'Dr. Custom Álvarez' === $submissions->find( $id )->submitted_details['profile']['advisor_name'], 'Custom names must update both the shared profile and the selected application.' );
			taas_advisor_check( 'custom-advisor@example.invalid' === $profiles->find( $ufid )->advisor_email && 'custom-advisor@example.invalid' === $submissions->find( $id )->submitted_details['profile']['advisor_email'], 'Custom advisor emails must persist in both records.' );
			taas_advisor_check( 409 === taas_advisor_save( 'hr', $id, $custom )->get_status(), 'Custom advisor changes must reject stale edits.' );
			$stale_email = $custom;
			$stale_email['expected_advisor_name'] = 'Dr. Custom Álvarez';
			$stale_email['expected_advisor_email'] = 'outdated@example.invalid';
			taas_advisor_check( 409 === taas_advisor_save( 'hr', $id, $stale_email )->get_status(), 'Email-only concurrent changes must not be overwritten.' );
			$details['profile']['enrollment_status'] = 1;
			$submissions->save_for_term( $ufid, TAAS_Semester::Spring, 2026, $details );
			taas_advisor_check( 400 === taas_advisor_save( 'hr', $id, $body )->get_status(), 'Undergraduates must not have advisor editors.' );
			$cycles->save( new TAAS_Application_Cycle( TAAS_Semester::Spring, 2026, array() ) );
			foreach ( array( 'hr', 'admin' ) as $role ) {
				taas_advisor_check( 403 === taas_advisor_save( $role, $id, $body )->get_status(), 'Closed HR review must refuse advisor corrections.' );
			}
			// Roll back all fixtures, including nested endpoint transactions.
			throw new RuntimeException( 'taas_advisor_fixture_rollback' );
		}
	);
} catch ( RuntimeException $error ) {
	if ( 'taas_advisor_fixture_rollback' !== $error->getMessage() ) {
		throw $error;
	}
}
echo 'PASS: HR advisor authorization, term isolation, validation, stale edits, and shared profile updates.' . PHP_EOL;
