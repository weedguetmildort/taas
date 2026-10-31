<?php
/**
 * Initial application page assets and authenticated API configuration.
 *
 * @package TAAS_Student_Pages
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'taas_student_application_assets' );

/** Load only the initial application page's JavaScript. */
function taas_student_application_assets(): void {
	if ( ! taas_student_pages_is_page( 'student-application' ) ) {
		return;
	}
	// TODO: Core resolves one priority role; update this caller with the deferred multi-role fix.
	// TODO: Verify upstream session/UFID mapping; retain the null-user check meanwhile.
	$user = TAAS_Auth::current_user();
	if ( null === $user || TAAS_Auth::ROLE_STUDENT !== $user['role'] ) {
		return;
	}
	$endpoint = rest_url( 'taas/v1/student/application' );
	if ( TAAS_Auth::is_local_dev() ) {
		$endpoint = add_query_arg( 'taas_role', 'student', $endpoint );
	}
	taas_student_enqueue_assets(
		array(
			'mode'      => 'create',
			'endpoint'  => $endpoint,
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'statusUrl' => TAAS_Pages::url( 'check-application-status' ),
			'homeUrl'   => TAAS_Pages::url( 'front-page' ),
		)
	);

}
