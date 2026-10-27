<?php
/**
 * REST controller for applications.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/applications. Admin-only: these routes do no ownership
 * checks. A professor reads their own courses' applications through
 * TAAS_REST_Professor_Page_Controller (taas/v1/professor/...) instead.
 */
final class TAAS_REST_Application_Controller extends TAAS_REST_Resource_Controller {

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( new TAAS_Application_Repository(), 'applications' );
	}
}
