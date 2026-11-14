<?php
/**
 * Admin-page frontend assets.
 *
 * @package TAAS_Admin_Pages
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'taas_admin_page_assets' );

/** Load the admin-page interactions for admin users only. */
function taas_admin_page_assets(): void {
	if ( ! taas_admin_pages_is_page() ) {
		return;
	}
	if ( ! TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ) ) {
		return;
	}

	$file = 'assets/admin-page.js';
	wp_enqueue_script(
		'taas-admin-page',
		TAAS_ADMIN_PAGES_URL . $file,
		array(),
		(string) filemtime( TAAS_ADMIN_PAGES_PATH . $file ),
		true
	);
	$endpoints = array(
		'studentsEndpoint'   => rest_url( 'taas/v1/students' ),
		'coursesEndpoint'    => rest_url( 'taas/v1/courses/import' ),
		'courseListEndpoint' => rest_url( 'taas/v1/courses' ),
		'professorsEndpoint' => rest_url( 'taas/v1/professors' ),
		'cycleEndpoint'      => rest_url( 'taas/v1/application-cycle' ),
	);
	if ( TAAS_Auth::is_local_dev() ) {
		$endpoints = array_map( static fn( $url ) => add_query_arg( 'taas_role', 'admin', $url ), $endpoints );
	}
	$config = $endpoints + array(
		'urls'  => array(
			'professor-page'                 => TAAS_Pages::url( 'professor-page' ),
			'manage-course-listings-admin'   => TAAS_Pages::url( 'manage-course-listings-admin' ),
			'view-student-application-admin' => TAAS_Pages::url( 'view-student-application-admin' ),
		),
		'nonce' => wp_create_nonce( 'wp_rest' ),
	);
	wp_add_inline_script( 'taas-admin-page', 'window.TAASAdminPage = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
}
