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

/**
 * Homepage URL for the caller's highest non-student role, or '' if they have none.
 *
 * @param array|null $user TAAS_Auth::current_user() result.
 * @return string
 */
function taas_student_pages_other_home( ?array $user ): string {
	$homes = array(
		TAAS_Auth::ROLE_ADMIN      => 'admin-page',
		TAAS_Auth::ROLE_HR         => 'hr-page',
		TAAS_Auth::ROLE_INSTRUCTOR => 'professor-homepage',
	);
	foreach ( $user['roles'] ?? array() as $role ) {
		if ( isset( $homes[ $role ] ) ) {
			return TAAS_Pages::url( $homes[ $role ] );
		}
	}
	return '';
}

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
	if ( TAAS_Auth::has_role( TAAS_Auth::ROLE_STUDENT ) ) {
		return;
	}
	$user = TAAS_Auth::current_user();
	$home = taas_student_pages_other_home( $user );
	// The front page is the site root, so staff land there first: send them home.
	if ( '' !== $home && taas_student_pages_is_page( 'front-page' ) ) {
		wp_safe_redirect( $home );
		exit;
	}
	if ( null !== $user && '' === $home ) {
		$message = '<p>Your account does not have access to TAAS yet. Please contact an administrator.</p>';
	} else {
		$message = '<p>You are not authorized to view this page.</p>';
	}
	if ( '' !== $home ) {
		$message .= '<p><a href="' . esc_url( $home ) . '">Go to your homepage</a></p>';
	}
	wp_die( wp_kses_post( $message ), 'Access denied', array( 'response' => null === $user ? 401 : 403 ) );
}
