<?php
/**
 * HR portal frontend setup.
 *
 * @package TAAS_HR_Page
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'taas_hr_page_assets' );

/** Load the HR assets only on the portal. */
function taas_hr_page_assets(): void {
	if ( ! taas_hr_page_is_portal() ) {
		return;
	}

	$css    = 'assets/hr-page.css';
	$script = 'assets/hr-page.js';
	wp_enqueue_style( 'taas-hr-page', TAAS_HR_PAGE_URL . $css, array(), (string) filemtime( TAAS_HR_PAGE_PATH . $css ) );
	wp_enqueue_script( 'taas-hr-page', TAAS_HR_PAGE_URL . $script, array(), (string) filemtime( TAAS_HR_PAGE_PATH . $script ), true );
}
