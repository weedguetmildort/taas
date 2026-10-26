<?php
/**
 * REST controller for applications.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/applications. Admin-only until ownership checks exist
 * (a student sees their own, an instructor sees their courses').
 */
final class TAAS_REST_Application_Controller extends TAAS_REST_Resource_Controller {

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( new TAAS_Application_Repository(), 'applications' );
	}
}
