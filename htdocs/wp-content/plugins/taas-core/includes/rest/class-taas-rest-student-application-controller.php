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

	/** Register student application routes. */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/student/application',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_form_data' ),
					'permission_callback' => array( $this, 'student_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_application' ),
					'permission_callback' => array( $this, 'student_permissions_check' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/student/applications',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_terms' ),
				'permission_callback' => array( $this, 'student_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/student/applications/(?P<semester>[1-5])/(?P<year>[0-9]{4})',
			array(
				'args'                => array(
					'year' => array( 'type' => 'integer', 'minimum' => 1901, 'maximum' => 2155 ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_application' ),
					'permission_callback' => array( $this, 'student_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_application' ),
					'permission_callback' => array( $this, 'student_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Enforce the current single-role auth contract and require a usable owner ID.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function student_permissions_check( $request ) {
		$allowed = ( TAAS_Auth::require_role( TAAS_Auth::ROLE_STUDENT ) )();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$user = TAAS_Auth::current_user();
		// TODO: Confirm production university_id-to-UFID mapping; never fall back to request input.
		if ( ! preg_match( '/^[0-9]{8}$/', (string) ( $user['university_id'] ?? '' ) ) ) {
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
	public function get_form_data( $request ) {
		return $this->respond(
			function () {
				$period = TAAS_Application_Period::current();
				if ( is_wp_error( $period ) ) {
					return $period;
				}
				$user       = TAAS_Auth::current_user();
				$ufid       = (string) $user['university_id'];
				$semester   = TAAS_Semester::from( $period['semester'] );
				$submission = ( new TAAS_Student_Submission_Repository() )->find_by_student_and_term( $ufid, $semester, $period['year'] );
				$choices    = ( new TAAS_Application_Repository() )->find_by_student_and_term( $ufid, $semester, $period['year'] );
				$profile    = ( new TAAS_Student_Profile_Repository() )->find( $ufid );
				$latest     = ( new TAAS_Student_Submission_Repository() )->latest_for_student( $ufid );
				$response = new WP_REST_Response( array(
					'period'          => $period,
					'identity'        => array( 'ufid' => $ufid, 'email' => $user['email'] ),
					'already_applied' => null !== $submission || ! empty( $choices ),
					'profile'         => $profile?->to_row(),
					'english_test'    => $latest?->submitted_details['profile']['english_test'] ?? null,
					'academic_background' => array_map(
						static fn( TAAS_Academic_Background $entry ) => $entry->to_row(),
						( new TAAS_Academic_Background_Repository() )->find_by_student( $ufid )
					),
					'courses'         => ( new TAAS_Course_Repository() )->application_choices( $semester, $period['year'] ),
				) );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
		);
	}

	/**
	 * Initial submission only; a conflicting existing submission is never overwritten.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_application( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_is_list( $body ) ) {
			return new WP_Error( 'taas_invalid_application', 'Send an application JSON object.', array( 'status' => 400 ) );
		}
		try {
			$input = TAAS_Student_Application_Input::parse( $body );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'taas_invalid_application', $e->getMessage(), array( 'status' => 400 ) );
		}
		return $this->respond(
			function () use ( $input ) {
				$user = TAAS_Auth::current_user();
				try {
					$saved = TAAS_Student_Application_Service::submit( (string) $user['university_id'], $input );
				} catch ( DomainException $e ) {
					return new WP_Error( 'taas_application_not_saved', $e->getMessage(), array( 'status' => $e->getCode() ) );
				}
				$response = new WP_REST_Response(
					$saved + array(
						'message'    => 'Your application was successfully submitted.',
						'status_url' => TAAS_Pages::url( 'check-application-status' ),
						'home_url'   => TAAS_Pages::url( 'front-page' ),
					),
					$saved['already_saved'] ? 200 : 201
				);
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
		);
	}

	/** List only this student's submitted academic terms. */
	public function get_terms( $request ) {
		return $this->respond(
			function () {
				$user = TAAS_Auth::current_user();
				$terms = ( new TAAS_Student_Submission_Repository() )->terms_for_student( (string) $user['university_id'] );
				$response = new WP_REST_Response(
					array( 'terms' => array_map(
						static fn( array $term ) => array(
							'submission_id' => (int) $term['submission_id'],
							'semester'      => (int) $term['semester'],
							'year'          => (int) $term['year'],
							'label'         => TAAS_Semester::from( (int) $term['semester'] )->label() . ' ' . $term['year'],
						),
						$terms
					) )
				);
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
		);
	}

	/**
	 * Retrieve a term using the authenticated owner, never a client-supplied UFID.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_application( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$path     = $request->get_url_params();
				$semester = TAAS_Semester::from( (int) $path['semester'] );
				$year     = (int) $path['year'];
				if ( $year < 1901 || $year > 2155 ) {
					return new WP_Error( 'taas_invalid_term', 'The requested application year is invalid.', array( 'status' => 400 ) );
				}
				$user     = TAAS_Auth::current_user();
				$ufid     = (string) $user['university_id'];
				$snapshot = ( new TAAS_Student_Submission_Repository() )->find_by_student_and_term( $ufid, $semester, $year );
				if ( null === $snapshot ) {
					return $this->not_found();
				}
				$choices = ( new TAAS_Application_Repository() )->find_by_student_and_term( $ufid, $semester, $year );
				$courses = ( new TAAS_Course_Repository() )->application_choices( $semester, $year );
				$by_id   = array_column( $courses, null, 'course_id' );
				$response = new WP_REST_Response(
					array(
						'submission_id' => $snapshot->submission_id,
						'term'          => array( 'semester' => $semester->value, 'year' => $year, 'label' => $semester->label() . ' ' . $year ),
						'details'       => $snapshot->submitted_details,
						'identity'      => array( 'ufid' => $ufid ),
						'can_edit'      => TAAS_Student_Application_Service::can_edit( $semester, $year, $choices ),
						'revision'      => TAAS_Student_Application_Service::revision( $snapshot, $choices ),
						'courses'       => $courses,
						'course_preferences' => array_map(
							static fn( array $row ) => array(
								'application_id' => (int) $row['application_id'],
								'course_id'      => $row['applied_course_id'],
								'course_code'    => $by_id[ $row['applied_course_id'] ]['course_code'] ?? '',
								'course_name'    => $by_id[ $row['applied_course_id'] ]['course_name'] ?? '',
								'rank'           => null === $row['student_preference_rank'] ? null : (int) $row['student_preference_rank'],
								'match_status'   => (int) $row['match_status'],
								'status_label'   => TAAS_Match_Status::from( (int) $row['match_status'] )->label(),
								'has_completed_course' => (bool) $row['has_taken_course'],
							),
							$choices
						),
					)
				);
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
		);
	}

	/** Save explicit edits from the status page; initial creation remains separate. */
	public function update_application( $request ) {
		$body = $request->get_json_params();
		$revision = is_array( $body ) ? ( $body['revision'] ?? null ) : null;
		if ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/', $revision ) ) {
			return new WP_Error( 'taas_invalid_revision', 'Load the saved application before submitting edits.', array( 'status' => 400 ) );
		}
		unset( $body['revision'] );
		try {
			$input = TAAS_Student_Application_Input::parse( $body, true );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'taas_invalid_application', $e->getMessage(), array( 'status' => 400 ) );
		}
		$path = $request->get_url_params();
		if ( $input['term']['semester'] !== (int) $path['semester'] || $input['term']['year'] !== (int) $path['year'] ) {
			return new WP_Error( 'taas_invalid_term', 'The submitted term does not match the application being edited.', array( 'status' => 400 ) );
		}
		return $this->respond(
			function () use ( $input, $revision ) {
				$user = TAAS_Auth::current_user();
				try {
					$saved = TAAS_Student_Application_Service::update( (string) $user['university_id'], $input, $revision );
				} catch ( DomainException $e ) {
					return new WP_Error( 'taas_application_not_saved', $e->getMessage(), array( 'status' => $e->getCode() ) );
				}
				$response = new WP_REST_Response( $saved + array( 'message' => 'Your application changes were saved.' ) );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			}
		);
	}
}
