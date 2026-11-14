<?php
/**
 * Plugin Name: TAAS Admin Page
 * Description: Admin page access control and frontend integration.
 * Version: 1.0.0
 * Author: Jules Bush
 * Requires at least: 6.5
 * Requires Plugins: taas-core
 *
 * @package TAAS_Admin_Pages
 */

defined( 'ABSPATH' ) || exit;

define( 'TAAS_ADMIN_PAGES_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAAS_ADMIN_PAGES_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'taas_admin_pages_init' );

/** Load the admin-page integration after the shared core plugin. */
function taas_admin_pages_init(): void {
	if ( ! class_exists( 'TAAS_Auth' ) || ! class_exists( 'TAAS_Pages' ) ) {
		return;
	}

	require_once TAAS_ADMIN_PAGES_PATH . 'includes/access.php';
	require_once TAAS_ADMIN_PAGES_PATH . 'pages/admin-page.php';
}
