<?php
/**
 * HR portal access.
 *
 * @package TAAS_HR_Page
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'taas_hr_page_require_staff', 1 );

/** Recognize the managed HR page by its saved ID or slug. */
function taas_hr_page_is_portal(): bool {
	$id = TAAS_Pages::id( 'hr-page' );
	return ( $id > 0 && is_page( $id ) ) || is_page( 'hr-page' );
}

/**
 * Protect the managed HR portal before rendering.
 */
function taas_hr_page_require_staff(): void {
	if ( ! taas_hr_page_is_portal() ) {
		return;
	}

	nocache_headers();
	$user = TAAS_Auth::current_user();
	if ( null === $user ) {
		wp_safe_redirect( wp_login_url( get_permalink( get_queried_object_id() ) ) );
		exit;
	}

	if ( array_intersect( $user['roles'], array( TAAS_Auth::ROLE_HR, TAAS_Auth::ROLE_ADMIN ) ) ) {
		return;
	}

	wp_die(
		esc_html__( 'You do not have permission to view the HR portal.', 'taas-hr-page' ),
		esc_html__( 'Access denied', 'taas-hr-page' ),
		array( 'response' => 403 )
	);
}
