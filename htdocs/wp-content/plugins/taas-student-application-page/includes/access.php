<?php
/**
 * Shared student-page authorization.
 *
 * @package TAAS_Student_Pages
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'taas_student_pages_require_student', 1 );

/**
 * Recognize managed pages by saved ID, with a slug fallback.
 *
 * @param string $slug Managed page slug.
 * @return bool
 */
function taas_student_pages_is_page( string $slug ): bool {
	$id = TAAS_Pages::id( $slug );
	return ( $id > 0 && is_page( $id ) ) || is_page( $slug );
}

// TODO: Fix core's multi-role handling. current_user() exposes only the first
// matching role (admin > HR > instructor > student), hiding other assigned roles.
// Use the existing single-role contract until the core API and callers are updated.
/** Protect every student page before rendering content or issuing a REST nonce. */
function taas_student_pages_require_student(): void {
	$protected = false;
	foreach ( array( 'front-page', 'student-application', 'check-application-status' ) as $slug ) {
		$protected = $protected || taas_student_pages_is_page( $slug );
	}
	if ( ! $protected ) {
		return;
	}
	nocache_headers();
	// TODO: Verify upstream authentication integration. current_user() may return
	// null despite the expectation that SSO runs first; this is not a confirmed bug.
	$user = TAAS_Auth::current_user();
	if ( null !== $user && TAAS_Auth::ROLE_STUDENT === $user['role'] ) {
		return;
	}
	$message = '<p>You are not authorized to view this page.</p>';
	$homes   = array(
		TAAS_Auth::ROLE_ADMIN      => 'admin-page',
		TAAS_Auth::ROLE_HR         => 'hr-page',
		TAAS_Auth::ROLE_INSTRUCTOR => 'professor-homepage',
	);
	$slug = $homes[ $user['role'] ?? '' ] ?? null;
	$url  = null === $slug ? '' : TAAS_Pages::url( $slug );
	if ( '' !== $url ) {
		$message .= '<p><a href="' . esc_url( $url ) . '">Go to your homepage</a></p>';
	}
	wp_die( wp_kses_post( $message ), 'Access denied', array( 'response' => null === $user ? 401 : 403 ) );
}
