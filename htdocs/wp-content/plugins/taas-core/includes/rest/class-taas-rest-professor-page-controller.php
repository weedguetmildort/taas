<?php
/**
 * REST controller for the professor page.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/professor: what a signed-in professor sees and does on
 * their page.
 *
 *   GET  /professor/courses?term=SummerB&year=2026        their courses in one term
 *   GET  /professor/courses/{course_id}/applications      applicant list for a course
 *   GET  /professor/courses/{course_id}/ranking           their saved ranking and notes
 *   POST /professor/courses/{course_id}/ranking           replace the ranking (and notes)
 *   GET  /professor/applications/{application_id}         one applicant's full details
 *
 * Unlike the admin-only /professors resource, every route is scoped to the
 * caller: the professor is identified by TAAS_Auth's university_id, never by
 * anything in the request. A course or application that does not exist, does
 * not belong to the caller, or (for an application) was withdrawn all return
 * the same 404, so the API never reveals which ids exist. No route takes or
 * returns a UFID: applicants are identified by application_id throughout.
 */
final class TAAS_REST_Professor_Page_Controller extends TAAS_REST_Controller {

	/**
	 * Route pattern for a course id.
	 */
	private const COURSE_ID_PATTERN = '(?P<course_id>\d+)';

	/**
	 * Largest course_notes accepted, matching courses.course_notes varchar(1000).
	 */
	private const NOTES_MAX_LENGTH = 1000;

	/**
	 * Courses table.
	 *
	 * @var TAAS_Course_Repository
	 */
	private TAAS_Course_Repository $courses;

	/**
	 * Applications table.
	 *
	 * @var TAAS_Application_Repository
	 */
	private TAAS_Application_Repository $applications;

	/**
	 * Students table.
	 *
	 * @var TAAS_Student_Repository
	 */
	private TAAS_Student_Repository $students;

	/**
	 * Student profiles table.
	 *
	 * @var TAAS_Student_Profile_Repository
	 */
	private TAAS_Student_Profile_Repository $profiles;

	/**
	 * Academic background table.
	 *
	 * @var TAAS_Academic_Background_Repository
	 */
	private TAAS_Academic_Background_Repository $backgrounds;

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( 'professor' );

		$this->courses      = new TAAS_Course_Repository();
		$this->applications = new TAAS_Application_Repository();
		$this->students     = new TAAS_Student_Repository();
		$this->profiles     = new TAAS_Student_Profile_Repository();
		$this->backgrounds  = new TAAS_Academic_Background_Repository();
	}

	/**
	 * Permission callback for every route here, reads and the ranking write alike.
	 *
	 * Professors hold the taas_instructor role in TAAS_Auth. Phase gating (only
	 * professors may write during the ranking week) is not enforced yet.
	 *
	 * @return callable Returns true or WP_Error when called.
	 */
	protected function read_permission(): callable {
		return TAAS_Auth::require_role( TAAS_Auth::ROLE_INSTRUCTOR );
	}

	/**
	 * Check the caller is a professor.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function professor_permissions_check( $request ) {
		return ( $this->read_permission() )();
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$course_id_arg = array(
			'course_id' => array(
				'description' => __( 'Course ID.', 'taas-core' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_courses' ),
					'permission_callback' => array( $this, 'professor_permissions_check' ),
					'args'                => array(
						'term' => array(
							'description'       => __( 'Semester, e.g. Fall, Spring, SummerA, SummerB or SummerC.', 'taas-core' ),
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_term' ),
						),
						'year' => array(
							'description' => __( 'Four-digit year, e.g. 2026.', 'taas-core' ),
							'type'        => 'integer',
							'required'    => true,
							'minimum'     => 1901,
							'maximum'     => 2155,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses/' . self::COURSE_ID_PATTERN . '/applications',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_applicants' ),
					'permission_callback' => array( $this, 'professor_permissions_check' ),
					'args'                => $course_id_arg,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses/' . self::COURSE_ID_PATTERN . '/ranking',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ranking' ),
					'permission_callback' => array( $this, 'professor_permissions_check' ),
					'args'                => $course_id_arg,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ranking' ),
					'permission_callback' => array( $this, 'professor_permissions_check' ),
					'args'                => $course_id_arg + array(
						'ranking'      => array(
							'description' => __( 'Ranked applicants, best first. Applicants left out are unranked.', 'taas-core' ),
							'type'        => 'array',
							'required'    => true,
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'rank'           => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'application_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
								),
								'required'             => array( 'rank', 'application_id' ),
								'additionalProperties' => false,
							),
						),
						'course_notes' => array(
							'description' => __( 'Notes for matching. Omit to keep the current notes; null or "" clears them.', 'taas-core' ),
							'type'        => array( 'string', 'null' ),
							'maxLength'   => self::NOTES_MAX_LENGTH,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/applications/(?P<application_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_applicant' ),
					'permission_callback' => array( $this, 'professor_permissions_check' ),
					'args'                => array(
						'application_id' => array(
							'description' => __( 'Application id.', 'taas-core' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * Validate the term query parameter against TAAS_Semester.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return true|WP_Error
	 */
	public function validate_term( $value ) {
		if ( is_string( $value ) && null !== TAAS_Semester::try_from_label( $value ) ) {
			return true;
		}

		return new WP_Error(
			'rest_invalid_param',
			__( 'term must be one of Fall, Spring, SummerA, SummerB or SummerC.', 'taas-core' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * GET /professor/courses: the caller's courses in one term.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_courses( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$ufid     = $this->current_ufid();
				$semester = TAAS_Semester::try_from_label( (string) $request['term'] );

				if ( '' === $ufid || null === $semester ) {
					return array();
				}

				$courses = $this->courses->find_by_professor_and_term( $ufid, $semester, (int) $request['year'] );

				return array_map(
					static fn( TAAS_Course $course ) => array(
						'course_id'          => $course->course_id,
						'course_code'        => $course->course_code,
						'course_name'        => $course->course_name,
						'course_description' => $course->course_description,
						'course_notes'       => $course->course_notes,
						'is_online'          => $course->is_online,
					),
					$courses
				);
			}
		);
	}

	/**
	 * GET /professor/courses/{course_id}/applications: the applicant list.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_applicants( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$course = $this->owned_course( (int) $this->path_param( $request, 'course_id' ) );

				if ( null === $course ) {
					return $this->not_found();
				}

				$applicants = array_map(
					static function ( array $row ) {
						$row['enrollment_status'] = $row['enrollment_status']?->label();
						return $row;
					},
					$this->applications->applicants_for_course( $course->course_id )
				);

				return array(
					'course_id'    => $course->course_id,
					'course_code'  => $course->course_code,
					'course_name'  => $course->course_name,
					'applications' => $applicants,
				);
			}
		);
	}

	/**
	 * GET /professor/courses/{course_id}/ranking: the saved ranking and notes.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_ranking( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$course = $this->owned_course( (int) $this->path_param( $request, 'course_id' ) );

				return null === $course ? $this->not_found() : $this->ranking_body( $course );
			}
		);
	}

	/**
	 * POST /professor/courses/{course_id}/ranking: replace the ranking, and the
	 * notes if given, in one transaction. Responds with the saved state, the
	 * same shape as GET.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_ranking( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$course = $this->owned_course( (int) $this->path_param( $request, 'course_id' ) );

				if ( null === $course ) {
					return $this->not_found();
				}

				$eligible = array_map(
					static fn( TAAS_Application $application ) => $application->application_id,
					$this->applications->find_active_by_course( $course->course_id )
				);

				try {
					$ordered_ids = TAAS_Ranking::parse( $request['ranking'], $eligible );
				} catch ( InvalidArgumentException $e ) {
					return new WP_Error(
						'taas_invalid_ranking',
						__( 'The ranking is not valid.', 'taas-core' ),
						array(
							'status' => 400,
							'reason' => $e->getMessage(),
						)
					);
				}

				$update_notes = $request->has_param( 'course_notes' );
				$notes        = $update_notes ? $this->clean_notes( $request['course_notes'] ) : null;

				TAAS_Transaction::run(
					function () use ( $course, $ordered_ids, $update_notes, $notes ) {
						$this->applications->set_professor_ranks( $course->course_id, $ordered_ids );

						if ( $update_notes ) {
							$this->courses->update_notes( $course->course_id, $notes );
						}
					}
				);

				$saved = $this->courses->find( $course->course_id );

				return null === $saved ? $this->not_found() : $this->ranking_body( $saved );
			}
		);
	}

	/**
	 * GET /professor/applications/{application_id}: one applicant in full.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_applicant( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$application = $this->applications->find( (int) $this->path_param( $request, 'application_id' ) );

				if ( null === $application || TAAS_Match_Status::Withdrawn === $application->match_status ) {
					return $this->not_found();
				}

				if ( null === $this->owned_course( $application->applied_course_id ) ) {
					return $this->not_found();
				}

				$student = $this->students->find( $application->student_ufid );

				if ( null === $student ) {
					return $this->not_found();
				}

				$profile     = $this->profiles->find( $student->student_ufid );
				$backgrounds = $this->backgrounds->find_by_student( $student->student_ufid );

				// Most recent degree first.
				usort(
					$backgrounds,
					static fn( TAAS_Academic_Background $a, TAAS_Academic_Background $b ) => $b->grad_date <=> $a->grad_date
				);

				return array(
					'application_id'      => $application->application_id,
					'student_email'       => $student->student_email,
					'student_name'        => $student->student_name,
					'major'               => $profile?->major,
					'gpa'                 => $profile?->gpa,
					'enrollment_status'   => $profile?->enrollment_status?->label(),
					'has_taken_course'    => $application->has_taken_course,
					'has_ta_experience'   => $profile?->has_ta_experience ?? false,
					'has_ta_for_course'   => $application->has_ta_for_course,
					'academic_background' => array_map(
						static fn( TAAS_Academic_Background $background ) => array(
							'university'  => $background->university,
							'degree_type' => $background->degree_type->label(),
							'major'       => $background->major,
							'minor'       => $background->minor,
							'grad_date'   => $background->grad_date->format( 'Y-m-d' ),
						),
						$backgrounds
					),
					'country_code'        => $profile?->country_code,
					'eap5836_status'      => $profile?->eap5836_status?->label(),
					'eap5837_status'      => $profile?->eap5837_status?->label(),
					'resume_url'          => $profile?->resume_url,
					'speak_score'         => $profile?->speak_score,
					'speak_date'          => $profile?->speak_date?->format( 'Y-m-d' ),
					'student_statement'   => $application->student_statement,
					'toefl_ibt_score'     => $profile?->toefl_ibt_score,
					'toefl_ibt_date'      => $profile?->toefl_ibt_date?->format( 'Y-m-d' ),
				);
			}
		);
	}

	/**
	 * Build the ranking response: ranked applicants in rank order, plus notes.
	 *
	 * Withdrawn applications are left out, as on the applicant list.
	 *
	 * @param TAAS_Course $course Course the ranking is for.
	 * @return array<string, mixed>
	 * @throws RuntimeException If the query fails.
	 */
	private function ranking_body( TAAS_Course $course ): array {
		$ranked = array_values(
			array_filter(
				$this->applications->find_active_by_course( $course->course_id ),
				static fn( TAAS_Application $application ) => null !== $application->professor_preference_rank
			)
		);

		usort(
			$ranked,
			static fn( TAAS_Application $a, TAAS_Application $b ) => $a->professor_preference_rank <=> $b->professor_preference_rank
		);

		return array(
			'course_id'    => $course->course_id,
			'ranking'      => array_map(
				static fn( TAAS_Application $application ) => array(
					'rank'           => $application->professor_preference_rank,
					'application_id' => $application->application_id,
				),
				$ranked
			),
			'course_notes' => $course->course_notes,
		);
	}

	/**
	 * The course with this id, if the caller teaches it.
	 *
	 * @param int $course_id Course ID from the request.
	 * @return TAAS_Course|null Null if the course is missing or belongs to someone else.
	 * @throws RuntimeException If the query fails.
	 */
	private function owned_course( int $course_id ): ?TAAS_Course {
		$ufid = $this->current_ufid();

		if ( '' === $ufid ) {
			return null;
		}

		$course = $this->courses->find( $course_id );

		return null !== $course && $course->professor_ufid === $ufid ? $course : null;
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
	private function path_param( $request, string $key ): string {
		return (string) ( $request->get_url_params()[ $key ] ?? '' );
	}

	/**
	 * The caller's UFID, or '' if TAAS_Auth cannot resolve one.
	 *
	 * @return string
	 */
	private function current_ufid(): string {
		$user = TAAS_Auth::current_user();

		return (string) ( $user['university_id'] ?? '' );
	}

	/**
	 * Normalize submitted notes; null, empty or whitespace-only clears them.
	 *
	 * Notes are stored as plain text, exactly as typed apart from trimming, and
	 * escaped on output. HTML-sanitizing here would rewrite a lone '<' as '&lt;',
	 * which the frontend would then have to decode, and could push a note that
	 * passed the maxLength check past the varchar(1000) column.
	 *
	 * @param mixed $value Validated course_notes value: a string or null.
	 * @return string|null
	 */
	private function clean_notes( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$notes = trim( $value );

		return '' === $notes ? null : $notes;
	}
}
