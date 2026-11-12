<?php
/**
 * Local integration checks for versioned HR review decisions.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, WordPress.WP.GlobalVariablesOverride -- Standalone CLI integration check with a local mock role.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development required.' );
}

function taas_hr_review_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

function taas_hr_review_post( array $batch, string $role = 'hr' ): WP_REST_Response {
	$_GET['taas_role'] = $role;
	$request = new WP_REST_Request( 'POST', '/taas/v1/hr/applicants/decisions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'decisions' => $batch ) ) );
	return rest_do_request( $request );
}

$rollback = new RuntimeException( 'Rollback test fixtures.' );
try {
	TAAS_Transaction::run(
		static function () use ( $rollback ) {
			$students    = new TAAS_Student_Repository();
			$submissions = new TAAS_Student_Submission_Repository();
			$cycles      = new TAAS_Application_Cycle_Repository();
			$cycle       = new TAAS_Application_Cycle(
				TAAS_Semester::Spring,
				2150,
				array(
					'hr_review' => array(
						'start_at' => new DateTimeImmutable( '-1 day' ),
						'end_at'   => null,
					),
				)
			);
			$cycles->save( $cycle );
			$ids = array();
			foreach ( array( '99999016', '99999017' ) as $ufid ) {
				taas_hr_review_check( null === $students->find( $ufid ), 'Fixture UFID already exists.' );
				$students->insert( new TAAS_Student( $ufid, 'Review fixture', 'review@example.invalid' ) );
				$ids[] = (string) $submissions->save_for_term( $ufid, $cycle->cycle_semester, $cycle->cycle_year, array() );
			}
			$outside_id = (string) $submissions->save_for_term( '99999016', TAAS_Semester::Fall, 2150, array() );
			$change     = static fn( string $id, string $decision, int $version ) => array(
				'submission_id' => $id,
				'decision'      => $decision,
				'version'       => $version,
			);
			$approve = $change( $ids[1], 'approve', 0 );
			$saved   = taas_hr_review_post( array( $approve ) );
			taas_hr_review_check( 200 === $saved->get_status(), 'First reviewer should save successfully.' );
			taas_hr_review_check( $change( $ids[1], 'approve', 1 ) === $saved->get_data()['reviews'][0], 'First save should return version one.' );
			taas_hr_review_check( array( 2, 1, 1 ) === array_values( $saved->get_data()['progress'] ), 'First approval should count once.' );

			$other = $change( $ids[0], 'reject', 0 );
			$stale = taas_hr_review_post( array( $other, $change( $ids[1], 'reject', 0 ) ), 'admin' );
			taas_hr_review_check( 409 === $stale->get_status() && 'taas_review_conflict' === $stale->get_data()['code'], 'A stale save must conflict.' );
			taas_hr_review_check( $change( $ids[1], 'approve', 1 ) === $stale->get_data()['conflicts'][0], 'Conflict should return the latest decision.' );
			taas_hr_review_check( array( 2, 1, 1 ) === array_values( $stale->get_data()['progress'] ), 'A stale batch must leave the other applicant unreviewed.' );
			taas_hr_review_check( 404 === taas_hr_review_post( array( $other, $change( $outside_id, 'approve', 0 ) ) )->get_status(), 'The batch must reject another term.' );
			taas_hr_review_check( 400 === taas_hr_review_post( array( $other, $other ) )->get_status(), 'The batch must reject duplicate IDs.' );
			taas_hr_review_check( 403 === taas_hr_review_post( array( $other ), 'student' )->get_status(), 'Students cannot save HR decisions.' );

			$revision = taas_hr_review_post( array( $other, $change( $ids[1], 'reject', 1 ) ), 'admin' );
			taas_hr_review_check( 200 === $revision->get_status() && array( 2, 2, 0 ) === array_values( $revision->get_data()['progress'] ), 'A revision and another applicant should save together with accurate totals.' );
			$list = rest_do_request( new WP_REST_Request( 'GET', '/taas/v1/hr/applicants' ) )->get_data();
			taas_hr_review_check( array( 1, 2 ) === array_column( $list['applicants'], 'version' ) && array( 'reject', 'reject' ) === array_column( $list['applicants'], 'decision' ), 'A fresh list must return both saved decisions.' );
			taas_hr_review_check( $revision->get_data()['progress'] === $list['progress'], 'A fresh list must return the saved progress.' );

			$reviews = new TAAS_HR_Review_Repository();
			$submissions->save_for_term( '99999017', $cycle->cycle_semester, $cycle->cycle_year, array( 'student_name' => 'Updated fixture' ) );
			$review = $reviews->find( $ids[1] );
			taas_hr_review_check( $review instanceof TAAS_HR_Review && 'reject' === $review->decision && 2 === $review->version, 'Updating a student snapshot must preserve its separate HR review.' );
			$cycles->save( new TAAS_Application_Cycle( $cycle->cycle_semester, $cycle->cycle_year, array() ) );
			taas_hr_review_check( 403 === taas_hr_review_post( array( $change( $ids[1], 'approve', 2 ) ), 'admin' )->get_status(), 'Closed HR review must block revisions.' );
			$students->delete( '99999017' );
			taas_hr_review_check( null === $reviews->find( $ids[1] ), 'Deleting a submission must remove its HR review.' );
			throw $rollback;
		}
	);
} catch ( RuntimeException $error ) {
	if ( $error !== $rollback ) {
		throw $error;
	}
}
echo 'PASS: Version conflicts, all-or-nothing saves, revisions, and persistent progress.' . PHP_EOL;
