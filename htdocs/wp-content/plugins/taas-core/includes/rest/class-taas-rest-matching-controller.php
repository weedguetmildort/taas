<?php
/**
 * REST controller for admin matching.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/matching, all admin-only.
 *
 *   GET  /matching/recommendations                     recommended matches for a term
 *   GET  /matching/recommendations/{course_id}         recommended matches for one course
 *   GET  /matching/courses                             current matches by course
 *   GET  /matching/courses/{course_id}                 current matches for one course
 *   GET  /matching/courses/{course_id}/applicants      the list the admin chooses from
 *   GET  /matching/students                            current matches by student
 *   GET  /matching/students/{student_ufid}             current matches for one student
 *   GET  /matching/progress                            a term's totals
 *   POST /matching/finalize                            reject everything still Pending
 *
 * The admin makes each match with PATCH /applications/{application_id}. Nothing
 * here runs the matching algorithm: the recommendation routes read what its
 * last run saved to match_recommendations.
 */
final class TAAS_REST_Matching_Controller extends TAAS_REST_Controller {

	use TAAS_REST_Term_Params;

	/**
	 * Applications table.
	 *
	 * @var TAAS_Application_Repository
	 */
	private TAAS_Application_Repository $applications;

	/**
	 * Courses table.
	 *
	 * @var TAAS_Course_Repository
	 */
	private TAAS_Course_Repository $courses;

	/**
	 * Match recommendations table.
	 *
	 * @var TAAS_Match_Recommendation_Repository
	 */
	private TAAS_Match_Recommendation_Repository $recommendations;

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( 'matching' );

		$this->applications    = new TAAS_Application_Repository();
		$this->courses         = new TAAS_Course_Repository();
		$this->recommendations = new TAAS_Match_Recommendation_Repository();
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$reads = array(
			'/recommendations'                            => array( 'get_recommendations', $this->get_collection_params() + $this->term_args() + array( 'student_ufid' => array( 'type' => 'string' ) ) ),
			'/recommendations/' . self::COURSE_ID_PATTERN => array( 'get_course_recommendations', array() ),
			'/courses'                                    => array( 'get_courses', $this->get_collection_params() + $this->term_args() ),
			'/courses/' . self::COURSE_ID_PATTERN         => array( 'get_course', array() ),
			'/courses/' . self::COURSE_ID_PATTERN . '/applicants' => array( 'get_applicants', array() ),
			'/students'                                   => array( 'get_students', $this->get_collection_params() + $this->term_args() ),
			'/students/(?P<student_ufid>[A-Za-z0-9_-]+)'  => array( 'get_student', $this->term_args() ),
			'/progress'                                   => array( 'get_progress', $this->term_args() ),
		);

		foreach ( $reads as $route => $spec ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . $route,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, $spec[0] ),
						'permission_callback' => $this->read_permission(),
						'args'                => $spec[1],
					),
				)
			);
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/finalize',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'finalize' ),
					'permission_callback' => TAAS_Auth::require_role( TAAS_Auth::ROLE_ADMIN ),
					'args'                => $this->term_args(),
				),
			)
		);
	}

	/**
	 * GET /matching/recommendations: a term's recommended matches.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_recommendations( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$term = $this->requested_term( $request );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				$items = $this->course_recommendations( $this->courses->find_by_term( $term[0], $term[1] ) );

				if ( $request->has_param( 'student_ufid' ) ) {
					$items = self::only_student( $items, (string) $request['student_ufid'] );
				}

				$per_page = (int) $request['per_page'];
				$offset   = ( (int) $request['page'] - 1 ) * $per_page;

				return $this->paged_response( array_slice( $items, $offset, $per_page ), count( $items ), $per_page );
			}
		);
	}

	/**
	 * GET /matching/recommendations/{course_id}: one course's recommended matches.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_course_recommendations( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$course = $this->courses->find( (int) $this->path_param( $request, 'course_id' ) );

				return null === $course ? $this->not_found() : $this->course_recommendations( array( $course ) )[0];
			}
		);
	}

	/**
	 * GET /matching/courses: one page of a term's courses with their matches.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_courses( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$term = $this->requested_term( $request );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				list( $semester, $year ) = $term;

				$per_page = (int) $request['per_page'];
				$entries  = $this->courses->search_with_professor( $semester, $year, null, null, $per_page, ( (int) $request['page'] - 1 ) * $per_page );

				return $this->paged_response(
					$this->course_matches( $entries ),
					$this->courses->count_search( $semester, $year ),
					$per_page
				);
			}
		);
	}

	/**
	 * GET /matching/courses/{course_id}: one course with its matches.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_course( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$entry = $this->courses->find_with_professor( (int) $this->path_param( $request, 'course_id' ) );

				return null === $entry ? $this->not_found() : $this->course_matches( array( $entry ) )[0];
			}
		);
	}

	/**
	 * GET /matching/courses/{course_id}/applicants: the list the admin chooses from.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_applicants( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$course = $this->courses->find( (int) $this->path_param( $request, 'course_id' ) );

				if ( null === $course ) {
					return $this->not_found();
				}

				return array(
					'course_id'    => $course->course_id,
					'course_notes' => $course->course_notes,
					'applicants'   => $this->applications->matching_applicants( $course->course_id ),
				);
			}
		);
	}

	/**
	 * GET /matching/students: one page of a term's applicants with their matches.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_students( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$term = $this->requested_term( $request );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				list( $semester, $year ) = $term;

				$per_page = (int) $request['per_page'];
				$students = $this->applications->students_for_term( $semester, $year, null, $per_page, ( (int) $request['page'] - 1 ) * $per_page );

				return $this->paged_response(
					$this->student_matches( $students, $semester, $year ),
					$this->applications->count_students_for_term( $semester, $year ),
					$per_page
				);
			}
		);
	}

	/**
	 * GET /matching/students/{student_ufid}: one applicant with their matches.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_student( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$term = $this->requested_term( $request );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				list( $semester, $year ) = $term;

				$students = $this->applications->students_for_term( $semester, $year, $this->path_param( $request, 'student_ufid' ) );

				return empty( $students ) ? $this->not_found() : $this->student_matches( $students, $semester, $year )[0];
			}
		);
	}

	/**
	 * GET /matching/progress: a term's totals.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_progress( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$term = $this->requested_term( $request );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				list( $semester, $year ) = $term;

				return $this->applications->progress_for_term( $semester, $year ) + array(
					'hours_required' => $this->courses->total_weekly_hours( $semester, $year ),
				);
			}
		);
	}

	/**
	 * POST /matching/finalize: reject every application in a term still Pending.
	 *
	 * Only runs for the current cycle's term during admin_matching. No active stage
	 * is not enough, since that is also the case between earlier stages, before HR
	 * or professors have seen the applications. Safe to run again.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function finalize( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$term = $this->requested_term( $request );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				list( $semester, $year ) = $term;

				$cycle = ( new TAAS_Application_Cycle_Repository() )->current();

				$is_open = null !== $cycle
					&& TAAS_Cycle_Stage::Admin_Matching === $cycle->active_stage()
					&& $semester === $cycle->cycle_semester
					&& $year === $cycle->cycle_year;

				if ( ! $is_open ) {
					return $this->conflict( 'taas_matching_not_open', __( 'Matches can only be finalized for the current cycle\'s term during admin_matching.', 'taas-core' ) );
				}

				$rejected = $this->applications->reject_pending_for_term( $semester, $year );

				return array(
					'term'                  => $semester->label(),
					'year'                  => $year,
					'applications_rejected' => $rejected,
					'matches_made'          => $this->applications->progress_for_term( $semester, $year )['matches_made'],
				);
			}
		);
	}

	/**
	 * Build response data for courses with their recommended matches.
	 *
	 * A course with no recommendations gets an empty list, with all its weekly_hours unfilled.
	 *
	 * @param TAAS_Course[] $courses Courses to describe.
	 * @return array<int, array<string, mixed>>
	 * @throws RuntimeException If the query fails.
	 */
	private function course_recommendations( array $courses ): array {
		$by_course = array();

		$course_ids = array_map( static fn( TAAS_Course $course ) => $course->course_id, $courses );

		foreach ( $this->recommendations->for_courses( $course_ids ) as $recommendation ) {
			$course_id = $recommendation['course_id'];
			unset( $recommendation['course_id'] );

			$by_course[ $course_id ][] = $recommendation;
		}

		return array_map(
			static function ( TAAS_Course $course ) use ( $by_course ) {
				$recommendations = $by_course[ $course->course_id ] ?? array();

				return array(
					'course_id'       => $course->course_id,
					'course_code'     => $course->course_code,
					'course_name'     => $course->course_name,
					'weekly_hours'    => $course->weekly_hours,
					'unfilled_hours'  => null === $course->weekly_hours ? null : $course->weekly_hours - array_sum( array_column( $recommendations, 'hours' ) ),
					'recommendations' => $recommendations,
				);
			},
			$courses
		);
	}

	/**
	 * Narrow recommendations to the courses recommending one student, with only them in each list.
	 *
	 * @param array  $items        Recommendation items.
	 * @param string $student_ufid Student's UFID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function only_student( array $items, string $student_ufid ): array {
		$narrowed = array();

		foreach ( $items as $item ) {
			$item['recommendations'] = array_values(
				array_filter(
					$item['recommendations'],
					static fn( array $recommendation ) => (string) $recommendation['student_ufid'] === $student_ufid
				)
			);

			if ( ! empty( $item['recommendations'] ) ) {
				$narrowed[] = $item;
			}
		}

		return $narrowed;
	}

	/**
	 * Build response data for courses with their matches.
	 *
	 * @param array<int, array{course: TAAS_Course, professor_name: string, professor_email: string}> $entries Courses joined to their professors.
	 * @return array<int, array<string, mixed>>
	 * @throws RuntimeException If the query fails.
	 */
	private function course_matches( array $entries ): array {
		$by_course = array();

		$course_ids = array_map( static fn( array $entry ) => $entry['course']->course_id, $entries );

		foreach ( $this->applications->matches_for_courses( $course_ids ) as $match ) {
			$by_course[ $match['course_id'] ][] = array(
				'application_id' => $match['application_id'],
				'student_ufid'   => $match['student_ufid'],
				'student_name'   => $match['student_name'],
				'assigned_hours' => $match['assigned_hours'],
			);
		}

		return array_map(
			static function ( array $entry ) use ( $by_course ) {
				$course  = $entry['course'];
				$matches = $by_course[ $course->course_id ] ?? array();

				return array(
					'course_id'      => $course->course_id,
					'course_code'    => $course->course_code,
					'course_name'    => $course->course_name,
					'professor_name' => $entry['professor_name'],
					'weekly_hours'   => $course->weekly_hours,
					'assigned_hours' => array_sum( array_column( $matches, 'assigned_hours' ) ),
					'matches'        => $matches,
				);
			},
			$entries
		);
	}

	/**
	 * Build response data for students with their matches in a term.
	 *
	 * @param array<int, array{student_ufid: string, student_name: string, student_email: string, available_hours: ?int}> $students Students from students_for_term().
	 * @param TAAS_Semester                                                                                               $semester Semester of the term.
	 * @param int                                                                                                         $year     Year of the term.
	 * @return array<int, array<string, mixed>>
	 * @throws RuntimeException If the query fails.
	 */
	private function student_matches( array $students, TAAS_Semester $semester, int $year ): array {
		$by_student = array();

		$matches = $this->applications->matches_for_students( array_column( $students, 'student_ufid' ), $semester, $year );

		foreach ( $matches as $match ) {
			$by_student[ $match['student_ufid'] ][] = array(
				'application_id' => $match['application_id'],
				'course_id'      => $match['course_id'],
				'course_code'    => $match['course_code'],
				'assigned_hours' => $match['assigned_hours'],
			);
		}

		return array_map(
			static function ( array $student ) use ( $by_student ) {
				$matches = $by_student[ $student['student_ufid'] ] ?? array();

				return $student + array(
					'assigned_hours' => array_sum( array_column( $matches, 'assigned_hours' ) ),
					'matches'        => $matches,
				);
			},
			$students
		);
	}
}
