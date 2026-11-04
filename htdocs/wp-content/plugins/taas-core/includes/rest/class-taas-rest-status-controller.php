<?php
/**
 * REST status endpoint.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * GET taas/v1/status: a smoke test for the whole path.
 *
 * Reports the plugin version, who the API thinks you are, and whether each
 * table exists, so one call proves routing, auth and the database connection.
 */
final class TAAS_REST_Status_Controller extends TAAS_REST_Controller {

	/**
	 * Create the controller. It uses no repository, so it extends the base
	 * directly rather than the single-table scaffold.
	 */
	public function __construct() {
		parent::__construct( 'status' );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => TAAS_Auth::require_authenticated(),
				),
			)
		);
	}

	/**
	 * Build the status report.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$user   = TAAS_Auth::current_user();
		$tables = array();

		foreach ( TAAS_Schema::names() as $bare ) {
			$tables[ $bare ] = TAAS_Schema::exists( $bare );
		}

		$status = array(
			'version'   => TAAS_CORE_VERSION,
			'local_dev' => TAAS_Auth::is_local_dev(),
			'role'      => $user['role'] ?? '',
			'roles'     => $user['roles'] ?? array(),
			'tables'    => $tables,
		);

		// Admins see their own Shibboleth session, to check what UF sends
		if ( ! TAAS_Auth::is_local_dev() && TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ) ) {
			$status['shibboleth'] = TAAS_Shibboleth::diagnostics( wp_get_current_user() );
		}

		return new WP_REST_Response( $status );
	}
}
