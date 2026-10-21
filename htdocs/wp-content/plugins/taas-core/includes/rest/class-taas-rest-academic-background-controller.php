<?php
/**
 * REST controller for academic background.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/academic-backgrounds. HR-only until ownership checks exist.
 */
final class TAAS_REST_Academic_Background_Controller extends TAAS_REST_Resource_Controller {

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( new TAAS_Academic_Background_Repository(), 'academic-backgrounds' );
	}

	/**
	 * Turn a row object into response data.
	 *
	 * Adds grad_label (MM/YYYY) to the table columns, so the frontend need not
	 * format grad_date itself.
	 *
	 * @param TAAS_Academic_Background $item    Row object from the repository.
	 * @param WP_REST_Request          $request Current request.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$data               = $item->to_row();
		$data['grad_label'] = $item->grad_label();

		return new WP_REST_Response( $data );
	}
}
