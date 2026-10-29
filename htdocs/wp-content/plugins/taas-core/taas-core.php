<?php
/**
 * Plugin Name: TAAS Core
 * Description: Provides shared foundation used by TAAS Onboarding and Matching
 * Version: 0.7.8.8.2.0
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'TAAS_CORE_VERSION', '0.7.8.8.2.0' );
define( 'TAAS_CORE_FILE', __FILE__ );
define( 'TAAS_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAAS_CORE_URL', plugin_dir_url( __FILE__ ) );

/*
 * Explicit requires
 */
require_once TAAS_CORE_PATH . 'includes/class-taas-logger.php';
require_once TAAS_CORE_PATH . 'includes/class-taas-version.php';
require_once TAAS_CORE_PATH . 'includes/class-taas-db.php';
require_once TAAS_CORE_PATH . 'includes/class-taas-auth.php';
require_once TAAS_CORE_PATH . 'includes/class-taas-pages.php';
require_once TAAS_CORE_PATH . 'includes/class-taas-schema.php';
require_once TAAS_CORE_PATH . 'includes/class-taas-migrations.php';
require_once TAAS_CORE_PATH . 'includes/class-taas-seeds.php';
require_once TAAS_CORE_PATH . 'includes/class-taas-snapshots.php';
require_once TAAS_CORE_PATH . 'includes/func-taas-csv-ingest.php';

/*
 * Types: enums and helpers first, then the row classes that depend on them.
 */
require_once TAAS_CORE_PATH . 'includes/types/enums/class-taas-enrollment-status.php';
require_once TAAS_CORE_PATH . 'includes/types/enums/class-taas-degree-type.php';
require_once TAAS_CORE_PATH . 'includes/types/enums/class-taas-course-taken-status.php';
require_once TAAS_CORE_PATH . 'includes/types/enums/class-taas-match-status.php';
require_once TAAS_CORE_PATH . 'includes/types/enums/class-taas-semester.php';
require_once TAAS_CORE_PATH . 'includes/types/helpers/class-taas-cast.php';
require_once TAAS_CORE_PATH . 'includes/types/helpers/class-taas-guard.php';
require_once TAAS_CORE_PATH . 'includes/types/helpers/class-taas-ranking.php';
require_once TAAS_CORE_PATH . 'includes/types/class-taas-professor.php';
require_once TAAS_CORE_PATH . 'includes/types/class-taas-student.php';
require_once TAAS_CORE_PATH . 'includes/types/class-taas-student-profile.php';
require_once TAAS_CORE_PATH . 'includes/types/class-taas-academic-background.php';
require_once TAAS_CORE_PATH . 'includes/types/class-taas-course.php';
require_once TAAS_CORE_PATH . 'includes/types/class-taas-application.php';

/*
 * Repositories layer for direct DB access.
 */
require_once TAAS_CORE_PATH . 'includes/repositories/class-taas-repository.php';
require_once TAAS_CORE_PATH . 'includes/repositories/class-taas-transaction.php';
require_once TAAS_CORE_PATH . 'includes/repositories/class-taas-professor-repository.php';
require_once TAAS_CORE_PATH . 'includes/repositories/class-taas-student-repository.php';
require_once TAAS_CORE_PATH . 'includes/repositories/class-taas-student-profile-repository.php';
require_once TAAS_CORE_PATH . 'includes/repositories/class-taas-academic-background-repository.php';
require_once TAAS_CORE_PATH . 'includes/repositories/class-taas-course-repository.php';
require_once TAAS_CORE_PATH . 'includes/repositories/class-taas-application-repository.php';

/*
 * RESTful API controllers.
 */
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-resource-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-status-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-professor-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-student-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-student-profile-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-academic-background-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-course-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-application-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest-professor-page-controller.php';
require_once TAAS_CORE_PATH . 'includes/rest/class-taas-rest.php';

TAAS_REST::init();

/**
 * Apply the database schema and any pending migrations
 *
 * @return void
 */
function taas_core_install(): void {
	TAAS_Auth::register_roles();
	TAAS_Pages::install();
	TAAS_Pages::sync_content();
	TAAS_Schema::install();
	TAAS_Migrations::run( 'core', TAAS_CORE_PATH . 'migrations/' );
	TAAS_DB::set_schema_version( 'core', TAAS_CORE_VERSION );
	TAAS_DB::set_fingerprint( 'core', TAAS_DB::fingerprint( TAAS_CORE_PATH ) );
}

/**
 * Activate plugin
 *
 * @return void
 */
function taas_core_activate(): void {
	global $wpdb;

	/**
	 * Guard MySQL plugin header field
	 */
	if ( version_compare( $wpdb->db_version(), '8.0', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'TAAS Core requires MySQL 8.0 or later.', 'taas-core' ),
			esc_html__( 'Plugin activation failed', 'taas-core' ),
			array( 'back_link' => true )
		);
	}

	taas_core_install();

	// Demo rows are for local development only, never the server.
	if ( TAAS_Auth::is_local_dev() ) {
		TAAS_Seeds::import( 'demo-data.json' );
	}

	TAAS_Logger::info( 'TAAS Core activated (version ' . TAAS_CORE_VERSION . ').' );
}
register_activation_hook( __FILE__, 'taas_core_activate' );

/**
 * Run the installer after an update, since activation does not re-fire
 *
 * @return void
 */
function taas_core_upgrade(): void {
	if ( ! is_admin() ) {
		return;
	}

	$fingerprint = TAAS_DB::fingerprint( TAAS_CORE_PATH );

	if ( ! TAAS_DB::needs_upgrade( 'core', TAAS_CORE_VERSION, $fingerprint ) ) {
		return;
	}

	// Another request on another node is already installing; let it finish
	if ( ! TAAS_DB::acquire_install_lock( 'core' ) ) {
		return;
	}

	try {
		TAAS_Logger::info(
			'Upgrading from ' . TAAS_DB::get_schema_version( 'core' ) . ' to ' . TAAS_CORE_VERSION . '.',
			array(
				'stored_fingerprint'  => TAAS_DB::get_fingerprint( 'core' ),
				'current_fingerprint' => $fingerprint,
			)
		);
		taas_core_install();
	} finally {
		TAAS_DB::release_install_lock( 'core' );
	}
}
add_action( 'admin_init', 'taas_core_upgrade' );

/**
 * Deactivate plugin
 *
 * @return void
 */
function taas_core_deactivate(): void {
	TAAS_Logger::info( 'TAAS Core deactivated.' );
}
register_deactivation_hook( __FILE__, 'taas_core_deactivate' );

/**
 * Boot the plugin
 *
 * @return void
 */
function taas_core_init(): void {
	load_plugin_textdomain( 'taas-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	/**
	 * Fire once TAAS Core is loaded and its helpers are available
	 *
	 * @since 0.7.8.8.2
	 */
	do_action( 'taas_core_loaded' );
}
add_action( 'plugins_loaded', 'taas_core_init' );

/**
 * Show a notice when local development mode is on
 *
 * @return void
 */
function taas_core_admin_notices(): void {
	if ( ! TAAS_Auth::is_local_dev() ) {
		return;
	}

	echo '<div class="notice notice-info"><p><strong>TAAS Core:</strong> ';
	echo esc_html__( 'Local development mode is ON. Authentication is mocked.', 'taas-core' );
	echo ' ' . esc_html( sprintf( 'Current mock role: %s', TAAS_Auth::local_dev_role() ) );
	echo '</p></div>';
}
add_action( 'admin_notices', 'taas_core_admin_notices' );
add_action( 'admin_notices', array( 'TAAS_Version', 'admin_notice' ) );

/*
 * Add the TAAS panel to Tools > Site Health > Info
 */
add_filter( 'debug_information', array( 'TAAS_Version', 'site_health_panel' ) );
