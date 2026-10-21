<?php
/**
 * REST controller for professors.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/professors. HR-only until a role needs more.
 */
final class TAAS_REST_Professor_Controller extends TAAS_REST_Resource_Controller {

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( new TAAS_Professor_Repository(), 'professors' );
	}
}
