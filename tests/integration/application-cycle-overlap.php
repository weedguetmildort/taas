<?php
/**
 * Verify independent application-stage windows through the local REST API.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment, WordPress.WP.GlobalVariablesOverride -- Standalone local fixtures are rolled back.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development required.' );
}

function taas_overlap_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

function taas_overlap_request( string $role, string $method, string $path, ?array $body = null ): WP_REST_Response {
	$_GET['taas_role'] = $role;
	$request = new WP_REST_Request( $method, '/taas/v1/' . $path );
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $request );
}

$rollback = new RuntimeException( 'Rollback overlapping-stage fixtures.' );
try {
	TAAS_Transaction::run(
		static function () use ( $rollback ) {
			$now = time();
			$date = static fn( int $offset ) => gmdate( 'Y-m-d\TH:i:s\Z', $now + $offset );
			$dates = array();
			foreach ( TAAS_Cycle_Stage::cases() as $index => $stage ) {
				$dates[ $stage->label() ] = array(
					'start_at' => $date( -14400 + $index * 1800 ),
					'end_at'   => $date( 14400 ),
				);
			}
			$response = taas_overlap_request(
				'admin',
				'PUT',
				'application-cycle',
				array(
					'term'        => 'Spring',
					'year'        => 2150,
					'stage_dates' => $dates,
				)
			);
			taas_overlap_check( 200 === $response->get_status() && 4 === count( $response->get_data()['stages'] ), 'PUT must save overlapping windows and report all four active stages.' );
			taas_overlap_check( TAAS_Application_Period::current()['is_open'], 'Student applications must remain open alongside later stages.' );
			foreach ( array( 'student', 'hr', 'instructor' ) as $role ) {
				taas_overlap_check( 200 === taas_overlap_request( $role, 'GET', 'courses' )->get_status(), 'Each role must be allowed during its own open stage.' );
			}
			taas_overlap_check( 200 === taas_overlap_request( 'hr', 'GET', 'hr/applicants' )->get_status(), 'The HR controller must remain open even when admin matching started later.' );
			$closed = array(
				'start_at' => $date( -7200 ),
				'end_at'   => $date( -3600 ),
			);
			$response = taas_overlap_request( 'admin', 'PATCH', 'application-cycle', array( 'stage_dates' => array( 'hr_review' => $closed ) ) );
			taas_overlap_check( 200 === $response->get_status() && 3 === count( $response->get_data()['stages'] ), 'Closing HR must not close the other stages.' );
			taas_overlap_check( 403 === taas_overlap_request( 'hr', 'GET', 'hr/applicants' )->get_status(), 'HR must close at its own deadline.' );
			taas_overlap_check( 200 === taas_overlap_request( 'student', 'GET', 'courses' )->get_status(), 'Students must remain allowed after HR closes.' );
			$separate = array(
				'student_application' => array(
					'start_at' => $date( 3600 ),
					'end_at'   => $date( 7200 ),
				),
				'hr_review'           => array(
					'start_at' => $date( 10800 ),
					'end_at'   => $date( 14400 ),
				),
			);
			$response = taas_overlap_request(
				'admin',
				'PUT',
				'application-cycle',
				array(
					'term'        => 'Spring',
					'year'        => 2150,
					'stage_dates' => $separate,
				)
			);
			taas_overlap_check( 200 === $response->get_status() && array() === $response->get_data()['stages'], 'Separate future windows must remain valid and closed until their starts.' );
			$touching = array(
				'start_at' => $date( 7200 ),
				'end_at'   => $date( 14400 ),
			);
			taas_overlap_check( 200 === taas_overlap_request( 'admin', 'PATCH', 'application-cycle', array( 'stage_dates' => array( 'hr_review' => $touching ) ) )->get_status(), 'Shared boundaries must be accepted.' );
			$invalid = array(
				'start_at' => $date( 7200 ),
				'end_at'   => $date( 3600 ),
			);
			taas_overlap_check( 400 === taas_overlap_request( 'admin', 'PATCH', 'application-cycle', array( 'stage_dates' => array( 'hr_review' => $invalid ) ) )->get_status(), 'An end before its start must still be rejected.' );
			throw $rollback;
		}
	);
} catch ( RuntimeException $error ) {
	if ( $error !== $rollback ) {
		throw $error;
	}
}
echo 'PASS: Overlapping and separate schedules, stage boundaries, and independent role access.' . PHP_EOL;
