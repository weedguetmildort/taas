<?php
/**
 * Read applicant summaries for the current HR review.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/** Serve the fields needed by the HR applicant table. */
final class TAAS_REST_HR_Applicants_Controller extends TAAS_REST_Controller {

	/** Create the controller. */
	public function __construct() {
		parent::__construct( 'hr/applicants' );
	}

	/** Register the applicant list route. */
	public function register_routes(): void {
		$require_hr = TAAS_Auth::require_role( TAAS_Auth::ROLE_HR );
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => static fn() => TAAS_Auth::has_role( TAAS_Auth::ROLE_ADMIN ) ? true : $require_hr(),
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
		return $this->respond(
			static function () {
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

				$submissions = ( new TAAS_Student_Submission_Repository() )->find_by_term( $cycle->cycle_semester, $cycle->cycle_year );
				return new WP_REST_Response(
					array(
						'term'       => $cycle->cycle_semester->label(),
						'year'       => $cycle->cycle_year,
						'applicants' => array_map( array( self::class, 'summary' ), $submissions ),
					),
					200,
					array( 'Cache-Control' => 'private, no-store' )
				);
			}
		);
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
		$type    = TAAS_Degree_Type::tryFrom( (int) ( $degree['degree_type'] ?? 0 ) );
		$score   = TAAS_English_Test_Score::tryFrom( (int) ( $profile['english_test_score'] ?? 0 ) );
		return array(
			'name'               => $details['student_name'] ?? null,
			'student_type'       => match ( $type ) {
				TAAS_Degree_Type::AA, TAAS_Degree_Type::BS => 'undergrad',
				TAAS_Degree_Type::MS  => 'masters',
				TAAS_Degree_Type::Phd => 'phd',
				null                  => null,
			},
			'gpa'                => TAAS_Cast::float_or_null( $degree['gpa'] ?? null ),
			'english_test_score' => $score?->label(),
			'country_code'       => $profile['country_code'] ?? null,
		);
	}
}
