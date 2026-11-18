<?php
/**
 * Application cycle page frontend assets.
 *
 * @package TAAS_Admin_Pages
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'taas_application_cycle_assets' );

/** Load the cycle page styles, form interactions, and Admin Home link. */
function taas_application_cycle_assets(): void {
	if ( ! taas_admin_pages_is_page( array( 'application-cycle-admin' ) ) || ! TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ) ) {
		return;
	}

	$style  = 'assets/application-cycle-admin.css';
	$script = 'assets/application-cycle-admin.js';
	wp_enqueue_style( 'taas-application-cycle', TAAS_ADMIN_PAGES_URL . $style, array(), (string) filemtime( TAAS_ADMIN_PAGES_PATH . $style ) );
	wp_enqueue_script( 'taas-application-cycle', TAAS_ADMIN_PAGES_URL . $script, array(), (string) filemtime( TAAS_ADMIN_PAGES_PATH . $script ), true );

	$home_url = TAAS_Pages::url( 'admin-page' );
	if ( '' !== $home_url && TAAS_Auth::is_local_dev() ) {
		$home_url = add_query_arg( 'taas_role', 'admin', $home_url );
	}
	wp_add_inline_script( 'taas-application-cycle', 'window.TAASApplicationCycle = ' . wp_json_encode( array( 'homeUrl' => $home_url ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
}
