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
 * Only HR and admins may see UFIDs. Every *_ufid column is removed from the
 * response for anyone else (see row_for_caller()), whatever the route's
 * read_permission() allows. Enum and boolean columns are sent as their logical
 * values, never the integers the database stores.
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
			'/' . $this->rest_base . '/(?P<id>' . $this->id_pattern() . ')',
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
	 * Route pattern for a primary key.
	 *
	 * @return string
	 */
	protected function id_pattern(): string {
		return '[A-Za-z0-9_-]+';
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

				return $this->paged_response( $data, $total, $per_page );
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
				$item = $this->repository->find( $this->path_param( $request, 'id' ) );

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
		return new WP_REST_Response( $this->row_for_caller( $item ) );
	}

	/**
	 * A row's columns as logical values, without any *_ufid column unless the caller is HR or an admin.
	 *
	 * @param object $item Row object from the repository.
	 * @return array<string, mixed>
	 */
	protected function row_for_caller( object $item ): array {
		$row = self::logical_row( $item );

		if ( $this->caller_sees_ufids() ) {
			return $row;
		}

		return array_filter(
			$row,
			static fn( string $column ) => ! str_ends_with( $column, '_ufid' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Whether the caller may see UFIDs: only HR and admins may.
	 *
	 * @return bool
	 */
	protected function caller_sees_ufids(): bool {
		return TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ) || TAAS_Auth::has_role( TAAS_Auth::ROLE_HR );
	}

	/**
	 * A row's columns, with each enum as its label and each boolean as true or false.
	 *
	 * Since to_row() gives the integers the database stores, these are read back from
	 * the row object's typed properties. A new enum column is covered automatically.
	 *
	 * @param object $item Row object from the repository.
	 * @return array<string, mixed>
	 */
	private static function logical_row( object $item ): array {
		$typed = get_object_vars( $item );
		$row   = $item->to_row();

		foreach ( array_keys( $row ) as $column ) {
			$value = $typed[ $column ] ?? null;

			if ( $value instanceof BackedEnum ) {
				$row[ $column ] = $value->label();
			} elseif ( is_bool( $value ) ) {
				$row[ $column ] = $value;
			}
		}

		return $row;
	}
}
