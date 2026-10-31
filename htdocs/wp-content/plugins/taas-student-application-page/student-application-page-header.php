<?php
/**
 * Plugin Name: Student Application Page
 * Description: Shared student page access and page-specific frontend integration.
 * Version: 1.0.0
 * Author: Shashank Gutta
 * Requires at least: 6.5
 * Requires Plugins: taas-core
 */

defined('ABSPATH') || exit;

define( 'TAAS_STUDENT_PAGES_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAAS_STUDENT_PAGES_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'taas_student_pages_init' );

/** Load the student-page integration after the shared core plugin. */
function taas_student_pages_init(): void {
	if ( ! class_exists( 'TAAS_Auth' ) || ! class_exists( 'TAAS_Pages' ) ) {
		return;
	}
	require_once TAAS_STUDENT_PAGES_PATH . 'includes/access.php';
	require_once TAAS_STUDENT_PAGES_PATH . 'includes/assets.php';
	require_once TAAS_STUDENT_PAGES_PATH . 'pages/student-application.php';
	require_once TAAS_STUDENT_PAGES_PATH . 'pages/check-application-status.php';
	require_once TAAS_STUDENT_PAGES_PATH . 'pages/front-page.php';
}

