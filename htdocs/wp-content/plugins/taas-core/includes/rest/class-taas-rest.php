<?php
/**
 * REST API registrar.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Registers every TAAS controller on rest_api_init.
 */
final class TAAS_REST {

	/**
	 * Hook route registration into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Build the controllers and let each register its routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$controllers = array(
			new TAAS_REST_Status_Controller(),
			new TAAS_REST_Professor_Controller(),
			new TAAS_REST_Student_Controller(),
			new TAAS_REST_Student_Profile_Controller(),
			new TAAS_REST_Academic_Background_Controller(),
			new TAAS_REST_Course_Controller(),
			new TAAS_REST_Application_Controller(),
		);

		/**
		 * Filter the REST controllers TAAS registers
		 *
		 * Lets taas-onboarding and taas-matching add their own controllers.
		 *
		 * @since 0.7.8.8.2
		 *
		 * @param WP_REST_Controller[] $controllers Controllers to register.
		 */
		$controllers = apply_filters( 'taas_rest_controllers', $controllers );

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
