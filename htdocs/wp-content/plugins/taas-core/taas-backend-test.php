<?php
/**
 * Plugin Name: TAAS Backend Test
 * Description: Testing ground for backend implementation and functionality
 * Version: 1.0
 *
 * @package TAAS_Backend_Test
 */

defined( 'ABSPATH' ) || exit;

define( 'TAAS_BACKEND_TEST_VERSION', '1.0' );
define( 'TAAS_BACKEND_TEST_FILE', __FILE__ );
define( 'TAAS_BACKEND_TEST_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAAS_BACKEND_TEST_URL', plugin_dir_url( __FILE__ ) );

/*
 * Explicit requires
 */
require_once TAAS_BACKEND_TEST_PATH . 'includes/func-taas-csv-ingest.php';

/**
 * Activate plugin
 *
 * @return void
 */
function taas_backend_test_activate(): void {
	global $wpdb;

	/**
	 * Guard MySQL plugin header field
	 */
	if ( version_compare( $wpdb->db_version(), '8.0', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'TAAS Backend Test requires MySQL 8.0 or later.', 'taas-backend-test' ),
			esc_html__( 'Plugin activation failed', 'taas-backend-test' ),
			array( 'back_link' => true )
		);
	}

	TAAS_Auth::register_roles();
	TAAS_Pages::install();
	TAAS_Logger::info( 'TAAS Backend Test activated (version ' . TAAS_BACKEND_TEST_VERSION . ').' );
}
register_activation_hook( __FILE__, 'taas_backend_test_activate' );

/**
 * Deactivate plugin
 *
 * @return void
 */
function taas_backend_test_deactivate(): void {
	TAAS_Logger::info( 'TAAS Backend Test deactivated.' );
}
register_deactivation_hook( __FILE__, 'taas_backend_test_deactivate' );

/**
 * Boot the plugin
 *
 * @return void
 */
function taas_backend_test_init(): void {
	load_plugin_textdomain( 'taas-backend-test', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	/**
	 * Fire once TAAS Core is loaded and its helpers are available
	 *
	 * @since 0.1.0
	 */
	do_action( 'taas_backend_test_loaded' );
}
add_action( 'plugins_loaded', 'taas_backend_test_init' );
