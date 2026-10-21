<?php
/**
 * REST controller for courses.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/courses. Any signed-in user may read, since students
 * browse courses to apply.
 */
final class TAAS_REST_Course_Controller extends TAAS_REST_Resource_Controller {

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( new TAAS_Course_Repository(), 'courses' );
	}

	/**
	 * Permission callback for the read routes.
	 * 
	 * Only requires user to be authenticated, rather than HR permissions.
	 *
	 * @return callable Returns true or WP_Error when called.
	 */
	protected function read_permission(): callable {
		return TAAS_Auth::require_authenticated();
	}
}
