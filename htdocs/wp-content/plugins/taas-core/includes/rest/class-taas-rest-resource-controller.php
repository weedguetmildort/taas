<?php
/**
 * REST controller scaffold for a single-table resource.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Read-only REST endpoints for one repository.
 *
 * Registers two routes under taas/v1/{rest_base}:
 *
 *   GET /{rest_base}        paginated list (?page=, ?per_page=)
 *   GET /{rest_base}/{id}   one row by primary key
 *
 * Use this when an endpoint is a plain view of one table. A subclass picks its
 * repository and base, and overrides read_permission() when admin-only is the
 * wrong default. Write routes (create, update, delete) are added per resource
 * as the frontend needs them.
 *
 * When an endpoint needs more than one table, do not stretch this class. Extend
 * TAAS_REST_Controller instead; its documentation covers composite reads, joins
 * and multi-table writes. A subclass here can still add extra routes that use
 * other repositories, as long as it registers them in its own register_routes()
 * after calling parent::register_routes().
 */
abstract class TAAS_REST_Resource_Controller extends TAAS_REST_Controller {

	/**
	 * Create a controller.
	 *
	 * @param TAAS_Repository $repository Data access for this resource's table.
	 * @param string          $rest_base  Route segment, e.g. 'courses'.
	 */
	public function __construct( protected TAAS_Repository $repository, string $rest_base ) {
		parent::__construct( $rest_base );
	}

	/**
	 * Register this resource's routes.
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
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Primary key of the row.', 'taas-core' ),
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 20,
						),
					),
				),
			)
		);
	}

	/**
	 * Check who may list rows.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return ( $this->read_permission() )();
	}

	/**
	 * Check who may read one row.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return ( $this->read_permission() )();
	}

	/**
	 * List rows, one page at a time.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$page     = (int) $request['page'];
				$per_page = (int) $request['per_page'];
				$total    = $this->repository->count();
				$items    = $this->repository->all( $per_page, ( $page - 1 ) * $per_page );
				$data     = array();

				foreach ( $items as $item ) {
					$data[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $item, $request ) );
				}

				$response = rest_ensure_response( $data );
				$response->header( 'X-WP-Total', (string) $total );
				$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

				return $response;
			}
		);
	}

	/**
	 * Read one row.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$item = $this->repository->find( (string) $request['id'] );

				return null === $item ? $this->not_found() : $this->prepare_item_for_response( $item, $request );
			}
		);
	}

	/**
	 * Turn a row object into response data.
	 *
	 * The row classes already know their column => value shape, so the API
	 * mirrors the table until a resource needs a different shape.
	 *
	 * @param object          $item    Row object from the repository.
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		return new WP_REST_Response( $item->to_row() );
	}

	/**
	 * Query parameters for the list route.
	 *
	 * @return array<string, array>
	 */
	public function get_collection_params() {
		return array(
			'page'     => array(
				'description' => __( 'Page of results to return.', 'taas-core' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page' => array(
				'description' => __( 'Rows per page.', 'taas-core' ),
				'type'        => 'integer',
				'default'     => TAAS_Repository::DEFAULT_LIMIT,
				'minimum'     => 1,
				'maximum'     => TAAS_Repository::MAX_LIMIT,
			),
		);
	}
}
