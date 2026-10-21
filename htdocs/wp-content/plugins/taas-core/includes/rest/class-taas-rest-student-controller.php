<?php
/**
 * REST controller for students.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/students. HR-only until ownership checks exist.
 */
final class TAAS_REST_Student_Controller extends TAAS_REST_Resource_Controller {

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( new TAAS_Student_Repository(), 'students' );
	}
}
