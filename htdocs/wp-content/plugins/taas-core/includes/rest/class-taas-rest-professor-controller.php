<?php
/**
 * REST controller for professors.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/professors. Admin-only until a role needs more.
 */
final class TAAS_REST_Professor_Controller extends TAAS_REST_Resource_Controller {

	/**
	 * Professors table.
	 *
	 * @var TAAS_Professor_Repository
	 */
	private TAAS_Professor_Repository $professors;

	/**
	 * Create the controller.
	 */
	public function __construct() {
		$this->professors = new TAAS_Professor_Repository();

		parent::__construct( $this->professors, 'professors' );
	}

	/**
	 * Route pattern for a professor_id.
	 *
	 * @return string
	 */
	protected function id_pattern(): string {
		return '\d+';
	}

	/**
	 * Query parameters for the list route.
	 *
	 * @return array<string, array>
	 */
	public function get_collection_params() {
		return parent::get_collection_params() + array(
			'search' => array(
				'description' => __( 'Text to look for in the name or email.', 'taas-core' ),
				'type'        => 'string',
			),
		);
	}

	/**
	 * GET /professors: one page of professors, optionally matching ?search=.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		if ( ! $request->has_param( 'search' ) ) {
			return parent::get_items( $request );
		}

		return $this->respond(
			function () use ( $request ) {
				$search   = (string) $request['search'];
				$page     = (int) $request['page'];
				$per_page = (int) $request['per_page'];
				$data     = array();

				foreach ( $this->professors->search( $search, $per_page, ( $page - 1 ) * $per_page ) as $professor ) {
					$data[] = $this->row_for_caller( $professor );
				}

				return $this->paged_response( $data, $this->professors->count_search( $search ), $per_page );
			}
		);
	}
}
