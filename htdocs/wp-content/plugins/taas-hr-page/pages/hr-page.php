<?php
/**
 * HR portal frontend setup.
 *
 * @package TAAS_HR_Page
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'taas_hr_page_assets' );

/** Load the HR assets and configure REST requests on the portal. */
function taas_hr_page_assets(): void {
	if ( ! taas_hr_page_is_portal() ) {
		return;
	}

	$css    = 'assets/hr-page.css';
	$script = 'assets/hr-page.js';
	wp_enqueue_style( 'taas-hr-page', TAAS_HR_PAGE_URL . $css, array(), (string) filemtime( TAAS_HR_PAGE_PATH . $css ) );
	wp_enqueue_script( 'taas-hr-page', TAAS_HR_PAGE_URL . $script, array(), (string) filemtime( TAAS_HR_PAGE_PATH . $script ), true );

	$endpoints = array(
		'cycleEndpoint'      => rest_url( 'taas/v1/application-cycle' ),
		'applicantsEndpoint' => rest_url( 'taas/v1/hr/applicants' ),
		'applicantEndpoint'  => rest_url( 'taas/v1/hr/applicants/__submission_id__' ),
	);
	if ( TAAS_Auth::is_local_dev() ) {
		$role      = substr( TAAS_Auth::local_dev_role(), strlen( 'taas_' ) );
		$endpoints = array_map( static fn( $url ) => add_query_arg( 'taas_role', $role, $url ), $endpoints );
	}
	wp_add_inline_script(
		'taas-hr-page',
		'window.TAASHrPage = ' . wp_json_encode(
			$endpoints + array( 'nonce' => wp_create_nonce( 'wp_rest' ) ),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		) . ';',
		'before'
	);
}
