<?php
/**
 * Plugin Name: TAAS HR Page
 * Description: Access and frontend integration for the TAAS HR portal.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires Plugins: taas-core
 * Text Domain: taas-hr-page
 *
 * @package TAAS_HR_Page
 */

defined( 'ABSPATH' ) || exit;

define( 'TAAS_HR_PAGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAAS_HR_PAGE_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'taas_hr_page_init' );

/**
 * Load the HR page integration after the shared core plugin.
 */
function taas_hr_page_init(): void {
	if ( class_exists( 'TAAS_Auth' ) && class_exists( 'TAAS_Pages' ) ) {
		require_once TAAS_HR_PAGE_PATH . 'includes/access.php';
		require_once TAAS_HR_PAGE_PATH . 'pages/hr-page.php';
	}
}
