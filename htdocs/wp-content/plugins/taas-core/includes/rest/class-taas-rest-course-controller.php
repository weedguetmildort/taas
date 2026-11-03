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
 * browse courses to apply, subject to TAAS_Stage_Gate. Only the admin may write.
 *
 * The write routes accept neither weekly_hours, which is always calculated
 * from enrollment and lab_hours, nor course_notes, which belongs to the professor.
 */
final class TAAS_REST_Course_Controller extends TAAS_REST_Resource_Controller {

	use TAAS_REST_Term_Params;

	/**
	 * Courses table.
	 *
	 * @var TAAS_Course_Repository
	 */
	private TAAS_Course_Repository $courses;

	/**
	 * Create the controller.
	 */
	public function __construct() {
		$this->courses = new TAAS_Course_Repository();

		parent::__construct( $this->courses, 'courses' );
	}

	/**
	 * Permission callback for the read routes.
	 *
	 * Only requires user to be authenticated, rather than admin permissions.
	 *
	 * @return callable Returns true or WP_Error when called.
	 */
	protected function read_permission(): callable {
		return TAAS_Auth::require_authenticated();
	}

	/**
	 * Route pattern for a course id.
	 *
	 * @return string
	 */
	protected function id_pattern(): string {
		return '\d+';
	}

	/**
	 * Register the read routes, then the admin's write routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		$admin_only = TAAS_Auth::require_role( TAAS_Auth::ROLE_ADMIN );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $admin_only,
					'args'                => $this->write_args( true ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $this->id_pattern() . ')',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $admin_only,
					'args'                => $this->write_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $admin_only,
				),
			)
		);
	}

	/**
	 * Query parameters for the list route.
	 *
	 * @return array<string, array>
	 */
	public function get_collection_params() {
		return parent::get_collection_params() + $this->term_args() + array(
			'course_code'    => array(
				'description' => __( 'Exact course code to match.', 'taas-core' ),
				'type'        => 'string',
			),
			'professor_ufid' => array(
				'description' => __( 'Professor to match. Admin and HR only; ignored for other callers.', 'taas-core' ),
				'type'        => 'string',
			),
		);
	}

	/**
	 * Fields accepted when adding or editing a course.
	 *
	 * @param bool $is_new True to require what a new course must have.
	 * @return array<string, array>
	 */
	private function write_args( bool $is_new ): array {
		$whole_number = array(
			'type'    => 'integer',
			'minimum' => 0,
			'maximum' => self::INT_COLUMN_MAX,
		);

		return $this->term_args( $is_new ) + array(
			'course_code'        => array(
				'type'      => 'string',
				'required'  => $is_new,
				'minLength' => 1,
				'maxLength' => 20,
				'pattern'   => '\S',
			),
			'course_name'        => array(
				'type'      => 'string',
				'required'  => $is_new,
				'minLength' => 1,
				'maxLength' => 255,
				'pattern'   => '\S',
			),
			'professor_ufid'     => array(
				'type'      => 'string',
				'required'  => $is_new,
				'minLength' => 1,
				'maxLength' => 8,
				'pattern'   => '^[A-Za-z0-9_-]+$',
			),
			'enrollment'         => $whole_number,
			'max_enrollment'     => $whole_number,
			'lab_hours'          => array(
				'type'              => 'integer',
				'validate_callback' => array( $this, 'validate_lab_hours' ),
			),
			'is_online'          => array( 'type' => 'boolean' ),
			'course_description' => array(
				'type'      => array( 'string', 'null' ),
				'maxLength' => 1000,
			),
		);
	}

	/**
	 * Validate lab_hours, which the staffing formula only defines for 0, 1 and 2.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return true|WP_Error
	 */
	public function validate_lab_hours( $value ) {
		if ( is_numeric( $value ) && in_array( (string) $value, array( '0', '1', '2' ), true ) ) {
			return true;
		}

		return new WP_Error(
			'rest_invalid_param',
			__( 'lab_hours must be 0, 1 or 2.', 'taas-core' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * GET /courses: one page of a term's courses.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$term = $this->requested_term( $request );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				list( $semester, $year ) = $term;

				$page     = (int) $request['page'];
				$per_page = (int) $request['per_page'];
				$code     = $request->has_param( 'course_code' ) ? (string) $request['course_code'] : null;
				$ufid     = $this->caller_sees_ufids() && $request->has_param( 'professor_ufid' ) ? (string) $request['professor_ufid'] : null;

				return $this->paged_response(
					array_map(
						array( $this, 'course_body' ),
						$this->courses->search_with_professor( $semester, $year, $code, $ufid, $per_page, ( $page - 1 ) * $per_page )
					),
					$this->courses->count_search( $semester, $year, $code, $ufid ),
					$per_page
				);
			}
		);
	}

	/**
	 * GET /courses/{course_id}: one course.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$entry = $this->courses->find_with_professor( (int) $this->path_param( $request, 'id' ) );

				return null === $entry ? $this->not_found() : $this->course_body( $entry );
			}
		);
	}

	/**
	 * POST /courses: add a course.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$course = $this->course_from_request( $request );
				$error  = $this->professor_error( $course ) ?? $this->duplicate_error( $course );

				if ( null !== $error ) {
					return $error;
				}

				$course_id = $this->unless_duplicate( fn() => (int) $this->courses->insert( $course ) );

				if ( is_wp_error( $course_id ) ) {
					return $course_id;
				}

				$entry = $this->courses->find_with_professor( $course_id );

				if ( null === $entry ) {
					return $this->not_found();
				}

				return new WP_REST_Response( $this->course_body( $entry ), 201 );
			}
		);
	}

	/**
	 * PATCH /courses/{course_id}: edit a course.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$course_id = (int) $this->path_param( $request, 'id' );

				// Lock the course until the update commits. A student submission locks it too while checking its term,
				// so a submission cannot add an application between the check below and the update.
				$error = $this->unless_duplicate(
					fn() => TAAS_Transaction::run(
						function () use ( $request, $course_id ) {
							$existing = $this->courses->find_locked( $course_id, true );

							if ( null === $existing ) {
								return $this->not_found();
							}

							$course = $this->course_from_request( $request, $existing );
							$error  = $this->professor_error( $course ) ?? $this->duplicate_error( $course, $course_id );

							if ( null !== $error ) {
								return $error;
							}

							$changes_term = $course->course_semester !== $existing->course_semester || $course->course_year !== $existing->course_year;

							if ( $changes_term && $this->has_applications( $course_id ) ) {
								return $this->has_applications_error();
							}

							$this->courses->update_details( $course_id, $course );

							return null;
						}
					)
				);

				if ( null !== $error ) {
					return $error;
				}

				$entry = $this->courses->find_with_professor( $course_id );

				return null === $entry ? $this->not_found() : $this->course_body( $entry );
			}
		);
	}

	/**
	 * DELETE /courses/{course_id}: delete a course that has no applications.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$course_id = (int) $this->path_param( $request, 'id' );

				// Same lock as update_item(), so a submission cannot add an application between the check and the delete.
				return TAAS_Transaction::run(
					function () use ( $course_id ) {
						if ( null === $this->courses->find_locked( $course_id, true ) ) {
							return $this->not_found();
						}

						if ( $this->has_applications( $course_id ) ) {
							return $this->has_applications_error();
						}

						$this->courses->delete( $course_id );

						return new WP_REST_Response( null, 204 );
					}
				);
			}
		);
	}

	/**
	 * Build the course a write request describes, keeping existing values for fields it leaves out.
	 *
	 * @param WP_REST_Request  $request  Current request.
	 * @param TAAS_Course|null $existing Course being edited; null when adding one.
	 * @return TAAS_Course
	 */
	private function course_from_request( $request, ?TAAS_Course $existing = null ): TAAS_Course {
		$value = static fn( string $param, mixed $fallback ) => $request->has_param( $param ) ? $request->get_param( $param ) : $fallback;

		$enrollment = (int) $value( 'enrollment', $existing?->enrollment ?? 0 );
		$lab_hours  = (int) $value( 'lab_hours', $existing?->lab_hours ?? 0 );

		return new TAAS_Course(
			course_id: $existing?->course_id,
			course_code: trim( (string) $value( 'course_code', $existing?->course_code ) ),
			course_name: trim( (string) $value( 'course_name', $existing?->course_name ) ),
			professor_ufid: (string) $value( 'professor_ufid', $existing?->professor_ufid ),
			course_semester: $request->has_param( 'term' ) ? TAAS_Semester::try_from_label( (string) $request['term'] ) : $existing->course_semester,
			course_year: (int) $value( 'year', $existing?->course_year ),
			is_online: (bool) $value( 'is_online', $existing?->is_online ?? false ),
			course_description: $value( 'course_description', $existing?->course_description ),
			course_notes: $existing?->course_notes,
			weekly_hours: taas_calc_weekly_hours( $enrollment, $lab_hours ),
			lab_hours: $lab_hours,
			enrollment: $enrollment,
			max_enrollment: (int) $value( 'max_enrollment', $existing?->max_enrollment ?? 0 ),
		);
	}

	/**
	 * A 400 if a course's professor does not exist.
	 *
	 * @param TAAS_Course $course Course to check.
	 * @return WP_Error|null
	 * @throws RuntimeException If the query fails.
	 */
	private function professor_error( TAAS_Course $course ): ?WP_Error {
		if ( null !== ( new TAAS_Professor_Repository() )->find( $course->professor_ufid ) ) {
			return null;
		}

		return $this->invalid_param( 'professor_ufid', __( 'professor_ufid must be an existing professor.', 'taas-core' ) );
	}

	/**
	 * A 409 if another course has the same term, year, code and name.
	 *
	 * @param TAAS_Course $course    Course to check.
	 * @param int|null    $course_id Course being edited, which cannot duplicate itself.
	 * @return WP_Error|null
	 * @throws RuntimeException If the query fails.
	 */
	private function duplicate_error( TAAS_Course $course, ?int $course_id = null ): ?WP_Error {
		$match = $this->courses->find_offering( $course->course_semester, $course->course_year, $course->course_code, $course->course_name );

		if ( null === $match || $match->course_id === $course_id ) {
			return null;
		}

		return $this->course_exists_error();
	}

	/**
	 * A 409 for a course whose term, year, code and name another course already has.
	 *
	 * @return WP_Error
	 */
	private function course_exists_error(): WP_Error {
		return $this->conflict( 'taas_course_exists', __( 'A course with this term, year, code and name already exists.', 'taas-core' ) );
	}

	/**
	 * Run a course write, turning a unique-key failure into the course_exists 409.
	 *
	 * duplicate_error() catches the usual case first. This covers a request that
	 * saved the same offering after that check ran, which the unique key stops.
	 *
	 * @param callable $write Callback that does the write.
	 * @return mixed What the callback returns, or the 409.
	 * @throws RuntimeException If the write fails for any other reason.
	 */
	private function unless_duplicate( callable $write ): mixed {
		try {
			return $write();
		} catch ( RuntimeException $e ) {
			if ( TAAS_Repository::DUPLICATE_KEY === $e->getCode() ) {
				return $this->course_exists_error();
			}

			throw $e;
		}
	}

	/**
	 * Whether a course has any application, including withdrawn ones.
	 *
	 * @param int $course_id Course ID.
	 * @return bool
	 * @throws RuntimeException If the query fails.
	 */
	private function has_applications( int $course_id ): bool {
		return ( new TAAS_Application_Repository() )->course_has_applications( $course_id );
	}

	/**
	 * A 409 for changing what a course's applications depend on.
	 *
	 * @return WP_Error
	 */
	private function has_applications_error(): WP_Error {
		return $this->conflict( 'taas_course_has_applications', __( 'This course has applications.', 'taas-core' ) );
	}

	/**
	 * Build one course's response data.
	 *
	 * @param array{course: TAAS_Course, professor_name: string, professor_email: string} $entry Course joined to its professor.
	 * @return array<string, mixed>
	 */
	private function course_body( array $entry ): array {
		return $this->row_for_caller( $entry['course'] ) + array(
			'professor_name'  => $entry['professor_name'],
			'professor_email' => $entry['professor_email'],
		);
	}
}
