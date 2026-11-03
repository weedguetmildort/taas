<?php
/**
 * REST controller for the current user.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * GET taas/v1/me: who the caller is, so pages can decide what to show.
 *
 * The flags only decide what to show; the server still checks each request.
 * Provisional until auth is finished: the flags are independent so one person
 * could be both admin and professor, but TAAS_Auth gives each user one role.
 */
final class TAAS_REST_Me_Controller extends TAAS_REST_Controller {

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( 'me' );
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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => TAAS_Auth::require_authenticated(),
				),
			)
		);
	}

	/**
	 * Describe the caller.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$user = TAAS_Auth::current_user();

		return new WP_REST_Response(
			array(
				'name'         => $user['display_name'] ?? '',
				'is_admin'     => TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ),
				'is_hr'        => TAAS_Auth::has_role( TAAS_Auth::ROLE_HR ),
				'is_professor' => TAAS_Auth::has_role( TAAS_Auth::ROLE_INSTRUCTOR ),
				'is_student'   => TAAS_Auth::has_role( TAAS_Auth::ROLE_STUDENT ),
			)
		);
	}
}
