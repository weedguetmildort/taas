<?php
/**
 * Read applicant summaries and submitted details for the current HR review.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/** Serve the HR applicant table and expanded application details. */
final class TAAS_REST_HR_Applicants_Controller extends TAAS_REST_Controller {

	/** Create the controller. */
	public function __construct() {
		parent::__construct( 'hr/applicants' );
	}

	/** Register the applicant list and detail routes. */
	public function register_routes(): void {
		$require_hr = TAAS_Auth::require_role( TAAS_Auth::ROLE_HR );
		$permission = static fn() => TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ) ? true : $require_hr();
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<submission_id>[1-9][0-9]{0,19})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * List submitted snapshots for the cycle currently in HR review.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		return $this->with_open_cycle(
			static function ( TAAS_Application_Cycle $cycle ) {
				$submissions = ( new TAAS_Student_Submission_Repository() )->find_by_term( $cycle->cycle_semester, $cycle->cycle_year );
				return self::private_response(
					array(
						'term'       => $cycle->cycle_semester->label(),
						'year'       => $cycle->cycle_year,
						'applicants' => array_map( array( self::class, 'summary' ), $submissions ),
					)
				);
			}
		);
	}

	/**
	 * Return the selected submission's details for the current cycle.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$id = $request->get_url_params()['submission_id'];
		return $this->with_open_cycle(
			function ( TAAS_Application_Cycle $cycle ) use ( $id ) {
				$submission_id = (int) $id;
				if ( (string) $submission_id !== $id ) {
					return $this->not_found();
				}
				$submission = ( new TAAS_Student_Submission_Repository() )->find_by_id_and_term( $submission_id, $cycle->cycle_semester, $cycle->cycle_year );
				if ( null === $submission ) {
					return $this->not_found();
				}
				return self::private_response( array( 'details' => self::details( $submission ) ) );
			}
		);
	}

	/**
	 * Enforce the current review stage for both reads, including admin requests.
	 *
	 * @param callable $work Callback that receives the current cycle.
	 * @return WP_REST_Response|WP_Error
	 */
	private function with_open_cycle( callable $work ) {
		return $this->respond(
			static function () use ( $work ) {
				$cycle = ( new TAAS_Application_Cycle_Repository() )->current();
				$stage = $cycle?->active_stage();
				if ( TAAS_Cycle_Stage::Hr_Review !== $stage ) {
					return new WP_Error(
						'taas_stage_closed',
						__( 'HR review is closed.', 'taas-core' ),
						array(
							'status' => 403,
							'stage'  => $stage?->label(),
						)
					);
				}

				return $work( $cycle );
			}
		);
	}

	/**
	 * Mark applicant responses as private and non-cacheable.
	 *
	 * @param array $data Response body.
	 * @return WP_REST_Response
	 */
	private static function private_response( array $data ): WP_REST_Response {
		return new WP_REST_Response( $data, 200, array( 'Cache-Control' => 'private, no-store' ) );
	}

	/**
	 * Project the submitted snapshot onto the applicant table fields.
	 *
	 * @param TAAS_Student_Submission $submission Saved term snapshot.
	 * @return array<string, string|float|null>
	 */
	private static function summary( TAAS_Student_Submission $submission ): array {
		$details = $submission->submitted_details;
		$degree  = $details['academic_background'][0] ?? array();
		$profile = $details['profile'] ?? array();
		$type    = TAAS_Enrollment_Status::tryFrom( (int) ( $profile['enrollment_status'] ?? -1 ) );
		$score   = TAAS_English_Test_Score::tryFrom( (int) ( $profile['english_test_score'] ?? 0 ) );
		return array(
			'submission_id'      => (string) $submission->submission_id,
			'name'               => $details['student_name'] ?? null,
			'student_type'       => match ( $type ) {
				TAAS_Enrollment_Status::Undergraduate => 'undergrad',
				TAAS_Enrollment_Status::Masters       => 'masters',
				TAAS_Enrollment_Status::Phd           => 'phd',
				TAAS_Enrollment_Status::Not_Enrolled, null => null,
			},
			'gpa'                => TAAS_Cast::float_or_null( $degree['gpa'] ?? null ),
			'english_test_score' => $score?->label(),
			'country_code'       => $profile['country_code'] ?? null,
		);
	}

	/**
	 * Select the supported student application fields from the saved term snapshot.
	 *
	 * @param TAAS_Student_Submission $submission Saved term snapshot.
	 * @return array
	 */
	private static function details( TAAS_Student_Submission $submission ): array {
		$snapshot     = $submission->submitted_details;
		$profile      = (array) ( $snapshot['profile'] ?? array() );
		$profile_keys = array( 'phone', 'country_code', 'admitted_semester', 'available_hours', 'enrollment_status', 'eap5836_status', 'eap5837_status', 'resume_url', 'additional_info', 'english_test_score' );
		$enrollment   = $profile['enrollment_status'] ?? null;
		if ( TAAS_Enrollment_Status::Phd->value === $enrollment ) {
			$profile_keys[] = 'advisor_name';
		}
		if ( TAAS_Enrollment_Status::Undergraduate->value === $enrollment ) {
			$profile_keys[] = 'fws_eligibility';
			$profile_keys[] = 'fws_award_letter_url';
		}
		$degree_keys  = array_flip( array( 'university', 'degree_type', 'major', 'minor', 'grad_date', 'gpa' ) );
		$degrees      = array();
		foreach ( (array) ( $snapshot['academic_background'] ?? array() ) as $degree ) {
			if ( is_array( $degree ) ) {
				$degrees[] = array_intersect_key( $degree, $degree_keys );
			}
		}
		return array(
			'student_name'        => $snapshot['student_name'] ?? null,
			'student_email'       => $snapshot['student_email'] ?? null,
			'profile'             => (object) array_intersect_key( $profile, array_flip( $profile_keys ) ),
			'academic_background' => $degrees,
		);
	}
}
