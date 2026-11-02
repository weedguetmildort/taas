<?php
/**
 * Base REST controller for TAAS routes.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Shared foundation for every TAAS REST controller.
 *
 * This class knows nothing about tables or repositories. It carries what every
 * controller needs: the taas/v1 namespace, a closed-by-default permission, and
 * helpers that turn repository failures into clean HTTP errors. What a
 * controller does with data is decided by which subclass it extends.
 *
 * Choosing a base for a new endpoint:
 *
 *   One table, plain list and get-by-id
 *     Extend TAAS_REST_Resource_Controller. It takes one repository and gives
 *     you both routes. Most single-table resources need nothing else.
 *
 *   Several tables behind one endpoint
 *     Extend this class directly. Hold as many repositories as the endpoint
 *     reads from, register your own routes, and wrap each callback body in
 *     respond() so a database failure becomes a logged 500:
 *
 *       final class TAAS_REST_Student_Overview_Controller extends TAAS_REST_Controller {
 *
 *           private TAAS_Student_Repository $students;
 *           private TAAS_Application_Repository $applications;
 *           private TAAS_Course_Repository $courses;
 *
 *           public function __construct() {
 *               parent::__construct( 'student-overview' );
 *               $this->students     = new TAAS_Student_Repository();
 *               $this->applications = new TAAS_Application_Repository();
 *               $this->courses      = new TAAS_Course_Repository();
 *           }
 *
 *           public function get_item( $request ) {
 *               return $this->respond(
 *                   function () use ( $request ) {
 *                       $student = $this->students->find( (string) $request['id'] );
 *                       if ( null === $student ) {
 *                           return $this->not_found();
 *                       }
 *                       $applications = $this->applications->find_by_student( $student->student_ufid );
 *                       // ...combine into one response array.
 *                   }
 *               );
 *           }
 *       }
 *
 *     Register it with the taas_rest_controllers filter, or add it to the
 *     list in TAAS_REST.
 *
 *   A true SQL join, or a database-side aggregate
 *     Do not put SQL in a controller. Add a method to the repository of the
 *     table the result is about (for example a course's applications joined to
 *     student names) and build the query with $wpdb->prepare() and
 *     TAAS_Repository::select_rows(). The controller calls that one method and
 *     can stay a single-repository controller. Use this when the database
 *     should do the filtering or counting. Use several repositories (above)
 *     when you simply need rows from a few tables and the result is small.
 *
 *   A write that touches several tables
 *     Wrap the repository calls in TAAS_Transaction::run() so they all commit
 *     or none do. Without it a failure halfway leaves a half-saved record.
 *
 * Permissions are per route, not per controller. A composite endpoint exposes
 * data from every table it reads, so its permission must be at least as strict
 * as the strictest of those tables. Ownership checks (a student sees only
 * their own rows) belong in the controller that needs them, never in the
 * request body. Which stage a non-admin may act in is not a controller's
 * concern: TAAS_Stage_Gate covers every route in the namespace.
 */
abstract class TAAS_REST_Controller extends WP_REST_Controller {

	/**
	 * Route pattern for a course id.
	 */
	protected const COURSE_ID_PATTERN = '(?P<course_id>\d+)';

	/**
	 * Largest value an int column can hold.
	 */
	protected const INT_COLUMN_MAX = 2147483647;

	/**
	 * Namespace shared by every TAAS route.
	 *
	 * @var string
	 */
	protected $namespace = 'taas/v1';

	/**
	 * Create a controller.
	 *
	 * @param string $rest_base Route segment, e.g. 'courses'.
	 */
	public function __construct( string $rest_base ) {
		$this->rest_base = $rest_base;
	}

	/**
	 * Permission callback for this controller's read routes.
	 *
	 * Admin-only by default, so a new endpoint is closed until someone decides
	 * who may read it.
	 *
	 * @return callable Returns true or WP_Error when called.
	 */
	protected function read_permission(): callable {
		return TAAS_Auth::require_role( TAAS_Auth::ROLE_ADMIN );
	}

	/**
	 * Run a callback body and turn repository failures into a clean 500.
	 *
	 * The callback may return anything rest_ensure_response() accepts: array
	 * data, a WP_REST_Response, or a WP_Error such as not_found(). A
	 * RuntimeException from any repository, or an InvalidArgumentException from a
	 * row that fails its type's checks, is logged and replaced with a generic
	 * error, so one try/catch does not repeat in every method.
	 *
	 * @param callable $work Callback that does the repository work.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function respond( callable $work ) {
		try {
			return rest_ensure_response( $work() );
		} catch ( RuntimeException | InvalidArgumentException $e ) {
			return $this->server_error( $e );
		}
	}

	/**
	 * A 404 for a record that does not exist.
	 *
	 * @return WP_Error
	 */
	protected function not_found(): WP_Error {
		return new WP_Error(
			'taas_not_found',
			__( 'No such record.', 'taas-core' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * A 400 for one invalid field, in the shape WordPress uses for its own validation.
	 *
	 * @param string $param   Field name.
	 * @param string $message What is wrong with it.
	 * @return WP_Error
	 */
	protected function invalid_param( string $param, string $message ): WP_Error {
		return new WP_Error(
			'rest_invalid_param',
			/* translators: %s: field name. */
			sprintf( __( 'Invalid parameter(s): %s', 'taas-core' ), $param ),
			array(
				'status' => 400,
				'params' => array( $param => $message ),
			)
		);
	}

	/**
	 * A 400 for missing fields, in the shape WordPress uses for its own validation.
	 *
	 * @param string[] $params Field names.
	 * @return WP_Error
	 */
	protected function missing_params( array $params ): WP_Error {
		return new WP_Error(
			'rest_missing_callback_param',
			/* translators: %s: comma-separated field names. */
			sprintf( __( 'Missing parameter(s): %s', 'taas-core' ), implode( ', ', $params ) ),
			array(
				'status' => 400,
				'params' => $params,
			)
		);
	}

	/**
	 * A 409 for a request that conflicts with the current state.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message for debugging.
	 * @return WP_Error
	 */
	protected function conflict( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 409 ) );
	}

	/**
	 * Wrap one page of a list, with its totals in the response headers.
	 *
	 * @param array $data     Items on this page.
	 * @param int   $total    Items across all pages.
	 * @param int   $per_page Page size.
	 * @return WP_REST_Response
	 */
	protected function paged_response( array $data, int $total, int $per_page ): WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * A parameter from the route path only.
	 *
	 * $request[ $key ] checks the JSON body and query string before the path,
	 * so a stray ?course_id= or body course_id would silently replace the id
	 * in the URL. Path ids are always read through here instead.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @param string          $key     Path parameter name, e.g. 'course_id'.
	 * @return string
	 */
	protected function path_param( $request, string $key ): string {
		return (string) ( $request->get_url_params()[ $key ] ?? '' );
	}

	/**
	 * Query parameters for a list route.
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

	/**
	 * Log a failure and return a generic 500.
	 *
	 * The message stays in the log, never in the response.
	 *
	 * @param Exception $e Failure caught by respond().
	 * @return WP_Error
	 */
	protected function server_error( Exception $e ): WP_Error {
		TAAS_Logger::error( $e->getMessage(), array( 'route' => $this->rest_base ) );

		return new WP_Error(
			'taas_server_error',
			__( 'The request could not be completed.', 'taas-core' ),
			array( 'status' => 500 )
		);
	}
}
