<?php
/** Status-page-only setup; term reads and writes remain in taas-core REST. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'taas_check_application_status_assets' );

/** Supply the status controller with authenticated, permalink-safe endpoints. */
function taas_check_application_status_assets(): void {
	if ( ! taas_student_pages_is_page( 'check-application-status' ) ) {
		return;
	}
	// TODO: Adapt with core's deferred multi-role fix; keep its single-role contract for now.
	$user = TAAS_Auth::current_user();
	if ( null === $user || TAAS_Auth::ROLE_STUDENT !== $user['role'] ) {
		return;
	}
	$list = rest_url( 'taas/v1/student/applications' );
	$term = rest_url( 'taas/v1/student/applications/__semester__/__year__' );
	if ( TAAS_Auth::is_local_dev() ) {
		$list = add_query_arg( 'taas_role', 'student', $list );
		$term = add_query_arg( 'taas_role', 'student', $term );
	}
	$nonce = wp_create_nonce( 'wp_rest' );
	taas_student_enqueue_assets( array( 'mode' => 'edit', 'nonce' => $nonce ) );
	$css = 'assets/check-application-status.css';
	wp_enqueue_style( 'taas-check-application-status', TAAS_STUDENT_PAGES_URL . $css, array( 'taas-student-application' ), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $css ) );
	$file = 'assets/check-application-status.js';
	wp_enqueue_script( 'taas-check-application-status', TAAS_STUDENT_PAGES_URL . $file, array( 'taas-student-application-edit' ), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $file ), true );
	wp_add_inline_script(
		'taas-check-application-status',
		'window.TAASStudentStatus = ' . wp_json_encode(
			array( 'listEndpoint' => $list, 'termEndpoint' => $term, 'nonce' => $nonce ),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		) . ';',
		'before'
	);
}
