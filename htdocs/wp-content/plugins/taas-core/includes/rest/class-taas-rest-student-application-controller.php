<?php
/**
 * Student-owned application reads, initial submission and explicit edits.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Student endpoints do not expose admin-only generic resources.
 */
final class TAAS_REST_Student_Application_Controller extends TAAS_REST_Controller {

	/** Create the controller. */
	public function __construct() {
		parent::__construct( 'student' );
	}

	/**
	 * All application endpoints require the same student permission check.
	 *
	 * @param string $methods Allowed HTTP methods.
	 * @param string $callback Controller callback name.
	 */
	private function student_endpoint( string $methods, string $callback ): array {
		return array(
			'methods'             => $methods,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'student_permissions_check' ),
		);
	}

	/** Register student application routes. */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/student/application',
			array(
				$this->student_endpoint( WP_REST_Server::READABLE, 'get_form_data' ),
				$this->student_endpoint( WP_REST_Server::CREATABLE, 'create_application' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/student/application/terms',
			$this->student_endpoint( WP_REST_Server::READABLE, 'get_terms' )
		);
		register_rest_route(
			$this->namespace,
			'/student/application/(?P<semester>[1-5])/(?P<year>[0-9]{4})',
			array(
				'args' => array(
					'year' => array(
						'type'    => 'integer',
						'minimum' => 1901,
						'maximum' => 2155,
					),
				),
				$this->student_endpoint( WP_REST_Server::READABLE, 'get_application' ),
				$this->student_endpoint( WP_REST_Server::EDITABLE, 'update_application' ),
			)
		);
	}

	/**
	 * Enforce the current single-role auth contract and require a usable owner ID.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function student_permissions_check( $request ): bool|WP_Error {
		$allowed = ( TAAS_Auth::require_role( TAAS_Auth::ROLE_STUDENT ) )();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! preg_match( '/^[0-9]{8}$/', $this->authenticated_ufid() ) ) {
			return new WP_Error( 'taas_student_identity_unavailable', 'Your verified student identifier is unavailable. Please contact the administrator.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Configuration, course choices and current shared details for initial prefilling.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_form_data( $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () {
				$period = TAAS_Application_Period::current();
				if ( is_wp_error( $period ) ) {
					return $period;
				}
				$user       = TAAS_Auth::current_user();
				$ufid       = $this->authenticated_ufid();
				$semester   = TAAS_Semester::from( $period['semester'] );
				$submission = ( new TAAS_Student_Submission_Repository() )->find_by_student_and_term( $ufid, $semester, $period['year'] );
				$choices    = ( new TAAS_Application_Repository() )->find_by_student_and_term( $ufid, $semester, $period['year'] );
				$profile    = ( new TAAS_Student_Profile_Repository() )->find( $ufid );
				$latest     = ( new TAAS_Student_Submission_Repository() )->latest_for_student( $ufid );
				$english_test = $latest?->submitted_details['profile']['english_test'] ?? null;
				if ( null === $latest && null !== $profile ) {
					$english_test = null !== $profile->toefl_ibt_score ? array(
						'type'  => 'TOEFL',
						'score' => $profile->toefl_ibt_score,
					)
						: ( null !== $profile->speak_score ? array(
							'type'  => 'SPEAK',
							'score' => $profile->speak_score,
						) : null );
				}
				return $this->student_response(
					array(
						'period'              => $period,
						'identity'            => array(
							'ufid'  => $ufid,
							'email' => $user['email'],
						),
						'already_applied'     => null !== $submission || ! empty( $choices ),
						'profile'             => $profile?->to_row(),
						'english_test'        => $english_test,
						'academic_background' => array_map(
							static fn( TAAS_Academic_Background $entry ) => $entry->to_row(),
							( new TAAS_Academic_Background_Repository() )->find_by_student( $ufid )
						),
						'courses'             => ( new TAAS_Course_Repository() )->application_choices( $semester, $period['year'] ),
					)
				);
			}
		);
	}

	/**
	 * Initial submission only; a conflicting existing submission is never overwritten.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_application( $request ): WP_REST_Response|WP_Error {
		$parsed = $this->parse_request( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$input = $parsed['input'];
		return $this->respond(
			function () use ( $input ) {
				try {
					$saved = TAAS_Student_Application_Service::submit( $this->authenticated_ufid(), $input );
				} catch ( DomainException $e ) {
					return new WP_Error( 'taas_application_not_saved', $e->getMessage(), array( 'status' => $e->getCode() ) );
				}
				return $this->student_response(
					$saved + array(
						'message'    => 'Your application was successfully submitted.',
						'status_url' => TAAS_Pages::url( 'check-application-status' ),
						'home_url'   => TAAS_Pages::url( 'front-page' ),
					),
					$saved['already_saved'] ? 200 : 201
				);
			}
		);
	}

	/**
	 * List only this student's submitted academic terms.
	 *
	 * @param mixed $request Current REST request.
	 */
	public function get_terms( $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () {
				$terms = ( new TAAS_Student_Submission_Repository() )->terms_for_student( $this->authenticated_ufid() );
				$terms = array_map(
					static fn( array $term ) => array(
						'submission_id' => (int) $term['submission_id'],
						'semester'      => (int) $term['semester'],
						'year'          => (int) $term['year'],
						'label'         => TAAS_Semester::from( (int) $term['semester'] )->label() . ' ' . $term['year'],
					),
					$terms
				);
				return $this->student_response( array( 'terms' => $terms ) );
			}
		);
	}

	/**
	 * Retrieve a term using the authenticated owner, never a client-supplied UFID.
	 *
	 * Response details is the saved submitted_details snapshot, not a current
	 * shared profile. Identity/term and course preferences are returned separately.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 * @see TAAS_Student_Submission::__construct() Schema of the response details object.
	 */
	public function get_application( $request ): WP_REST_Response|WP_Error {
		return $this->respond(
			function () use ( $request ) {
				$path     = $request->get_url_params();
				$semester = TAAS_Semester::from( (int) $path['semester'] );
				$year     = (int) $path['year'];
				if ( $year < 1901 || $year > 2155 ) {
					return new WP_Error( 'taas_invalid_term', 'The requested application year is invalid.', array( 'status' => 400 ) );
				}
				$ufid = $this->authenticated_ufid();
				$application = ( new TAAS_Student_Submission_Repository() )->find_application_for_term( $ufid, $semester, $year );
				if ( null === $application ) {
					return $this->not_found();
				}
				$snapshot = $application['snapshot'];
				$choices = $application['choices'];
				$courses = ( new TAAS_Course_Repository() )->application_choices( $semester, $year );
				return $this->student_response(
					array(
						'submission_id'      => $snapshot->submission_id,
						'term'               => array(
							'semester' => $semester->value,
							'year'     => $year,
							'label'    => $semester->label() . ' ' . $year,
						),
						'details'            => $snapshot->submitted_details,
						'identity'           => array( 'ufid' => $ufid ),
						'can_edit'           => TAAS_Student_Application_Service::can_edit( $semester, $year, $choices ),
						'revision'           => TAAS_Student_Application_Service::revision( $snapshot, $choices ),
						'courses'            => $courses,
						'course_preferences' => $this->format_preferences( $choices, $courses ),
					)
				);
			}
		);
	}

	/**
	 * Save explicit edits from the status page; initial creation remains separate.
	 *
	 * @param mixed $request Current REST request.
	 */
	public function update_application( $request ): WP_REST_Response|WP_Error {
		$parsed = $this->parse_request( $request, true );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$input = $parsed['input'];
		$revision = $parsed['revision'];
		$path = $request->get_url_params();
		if ( $input['term']['semester'] !== (int) $path['semester'] || $input['term']['year'] !== (int) $path['year'] ) {
			return new WP_Error( 'taas_invalid_term', 'The submitted term does not match the application being edited.', array( 'status' => 400 ) );
		}
		return $this->respond(
			function () use ( $input, $revision ) {
				try {
					$saved = TAAS_Student_Application_Service::update( $this->authenticated_ufid(), $input, $revision );
				} catch ( DomainException $e ) {
					return new WP_Error( 'taas_application_not_saved', $e->getMessage(), array( 'status' => $e->getCode() ) );
				}
				return $this->student_response( $saved + array( 'message' => 'Your application changes were saved.' ) );
			}
		);
	}

	/**
	 * Resolve ownership only from authentication, after the route permission check.
	 * TODO: Confirm production university_id-to-UFID mapping; never use request input.
	 */
	private function authenticated_ufid(): string {
		return (string) ( TAAS_Auth::current_user()['university_id'] ?? '' );
	}

	/**
	 * Personal application responses must not be cached.
	 *
	 * @param array $data Response payload.
	 * @param int   $status HTTP response status.
	 */
	private function student_response( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Parse create/edit requests while retaining the existing validation errors.
	 *
	 * @return array{input: array, revision: ?string}|WP_Error
	 * @param WP_REST_Request $request Current REST request.
	 * @param bool            $editing Whether the request edits a saved application.
	 */
	private function parse_request( WP_REST_Request $request, bool $editing = false ): array|WP_Error {
		$body = $request->get_json_params();
		$revision = null;
		if ( $editing ) {
			$revision = is_array( $body ) ? ( $body['revision'] ?? null ) : null;
			if ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/', $revision ) ) {
				return new WP_Error( 'taas_invalid_revision', 'Load the saved application before submitting edits.', array( 'status' => 400 ) );
			}
			unset( $body['revision'] );
		} elseif ( ! is_array( $body ) || array_is_list( $body ) ) {
			return new WP_Error( 'taas_invalid_application', 'Send an application JSON object.', array( 'status' => 400 ) );
		}
		try {
			return array(
				'input'    => TAAS_Student_Application_Input::parse( $body, $editing ),
				'revision' => $revision,
			);
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'taas_invalid_application', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Format course rows for the browser without exposing other database fields.
	 *
	 * @param array $choices Saved application rows.
	 * @param array $courses Available course rows.
	 */
	private function format_preferences( array $choices, array $courses ): array {
		$by_id = array_column( $courses, null, 'course_id' );
		return array_map(
			static fn( array $row ) => array(
				'application_id'       => (int) $row['application_id'],
				'course_id'            => $row['applied_course_id'],
				'course_code'          => $by_id[ $row['applied_course_id'] ]['course_code'] ?? '',
				'course_name'          => $by_id[ $row['applied_course_id'] ]['course_name'] ?? '',
				'rank'                 => null === $row['student_preference_rank'] ? null : (int) $row['student_preference_rank'],
				'match_status'         => (int) $row['match_status'],
				'status_label'         => TAAS_Match_Status::from( (int) $row['match_status'] )->label(),
				'has_completed_course' => (bool) $row['has_taken_course'],
			),
			$choices
		);
	}
}
