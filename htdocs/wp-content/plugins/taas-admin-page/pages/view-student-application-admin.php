<?php
/**
 * Matching portal frontend assets.
 *
 * @package TAAS_Admin_Pages
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'taas_admin_matching_portal_assets' );

/** Load the matching portal's styles and interactions on its managed page. */
function taas_admin_matching_portal_assets(): void {
	$slug = 'view-student-application-admin';
	$id   = TAAS_Pages::id( $slug );
	if ( ! is_page( $slug ) && ( $id <= 0 || ! is_page( $id ) ) ) {
		return;
	}

	$style = 'assets/view-student-application-admin.css';
	wp_enqueue_style( 'taas-admin-matching-portal', TAAS_ADMIN_PAGES_URL . $style, array(), (string) filemtime( TAAS_ADMIN_PAGES_PATH . $style ) );

	$script = 'assets/view-student-application-admin.js';
	wp_enqueue_script( 'taas-admin-matching-portal', TAAS_ADMIN_PAGES_URL . $script, array(), (string) filemtime( TAAS_ADMIN_PAGES_PATH . $script ), true );

	if ( ! TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ) ) {
		return;
	}

	$endpoints = array(
		'recommendationsEndpoint' => rest_url( 'taas/v1/matching/recommendations' ),
		'courseMatchesEndpoint'   => rest_url( 'taas/v1/matching/courses' ),
		'studentMatchesEndpoint'  => rest_url( 'taas/v1/matching/students' ),
		'progressEndpoint'        => rest_url( 'taas/v1/matching/progress' ),
		'runEndpoint'             => rest_url( 'taas/v1/matching/run' ),
		'cycleEndpoint'           => rest_url( 'taas/v1/application-cycle' ),
		'applicationEndpoint'     => rest_url( 'taas/v1/applications/__application_id__' ),
	);
	if ( TAAS_Auth::is_local_dev() ) {
		$endpoints = array_map( static fn( $url ) => add_query_arg( 'taas_role', 'admin', $url ), $endpoints );
	}
	$config = $endpoints + array( 'nonce' => wp_create_nonce( 'wp_rest' ) );
	wp_add_inline_script( 'taas-admin-matching-portal', 'window.TAASMatchingPortal = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
}
