<?php
/**
 * Admin-page authorization.
 *
 * @package TAAS_Admin_Pages
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'taas_admin_pages_require_admin', 1 );

/** Recognize the managed page by its saved ID, with a slug fallback. */
function taas_admin_pages_is_page(): bool {
	foreach ( array( 'admin-page', 'manage-course-listings-admin' ) as $slug ) {
		$id = TAAS_Pages::id( $slug );
		if ( ( $id > 0 && is_page( $id ) ) || is_page( $slug ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Get the homepage for the caller's highest non-admin role.
 *
 * @param array|null $user The current user, or null when signed out.
 * @return string The homepage URL, or an empty string if none is available.
 */
function taas_admin_pages_home( ?array $user ): string {
	$homes = array(
		TAAS_Auth::ROLE_HR         => 'hr-page',
		TAAS_Auth::ROLE_INSTRUCTOR => 'professor-homepage',
		TAAS_Auth::ROLE_STUDENT    => 'front-page',
	);

	foreach ( $user['roles'] ?? array() as $role ) {
		if ( isset( $homes[ $role ] ) ) {
			return TAAS_Pages::url( $homes[ $role ] );
		}
	}

	return '';
}

/** Protect the admin page before rendering its content. */
function taas_admin_pages_require_admin(): void {
	if ( ! taas_admin_pages_is_page() ) {
		return;
	}

	nocache_headers();
	if ( TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ) ) {
		return;
	}

	$user = TAAS_Auth::current_user();
	if ( null === $user ) {
		$here = isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] )
			? set_url_scheme( 'http://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) )
			: home_url( '/' );
		wp_safe_redirect( wp_login_url( $here ) );
		exit;
	}

	$home    = taas_admin_pages_home( $user );
	$message = '<p>You are not authorized to view this page.</p>';
	if ( '' !== $home ) {
		$message .= '<p><a href="' . esc_url( $home ) . '">Go to your homepage</a></p>';
	}

	wp_die( wp_kses_post( $message ), 'Access denied', array( 'response' => 403 ) );
}
