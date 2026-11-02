<?php
/**
 * REST controller for applications.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/applications. Admin-only: these routes do no ownership
 * checks. A professor reads their own courses' applications through
 * TAAS_REST_Professor_Page_Controller (taas/v1/professor/...) instead.
 *
 * A match is not a separate record: PATCH makes one by setting an application's
 * match_status and assigned_hours. The server does not compare a match to the
 * rankings, the course's remaining hours or the student's available_hours, since
 * the admin decides every match.
 */
final class TAAS_REST_Application_Controller extends TAAS_REST_Resource_Controller {

	/**
	 * Applications table.
	 *
	 * @var TAAS_Application_Repository
	 */
	private TAAS_Application_Repository $applications;

	/**
	 * Create the controller.
	 */
	public function __construct() {
		$this->applications = new TAAS_Application_Repository();

		parent::__construct( $this->applications, 'applications' );
	}

	/**
	 * Route pattern for an application id.
	 *
	 * @return string
	 */
	protected function id_pattern(): string {
		return '\d+';
	}

	/**
	 * Register the read routes, then PATCH.
	 *
	 * @return void
	 */
	public function register_routes() {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>' . $this->id_pattern() . ')',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => TAAS_Auth::require_role( TAAS_Auth::ROLE_ADMIN ),
					'args'                => array(
						'match_status'   => array(
							'description'       => __( 'Matched, Pending or Rejected.', 'taas-core' ),
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_match_status' ),
						),
						'assigned_hours' => array(
							'description' => __( 'TA hours to assign. Required, and at least 1, when setting Matched.', 'taas-core' ),
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => self::INT_COLUMN_MAX,
						),
					),
				),
			)
		);
	}

	/**
	 * Validate a requested match_status. Only a student can withdraw.
	 *
	 * @param mixed $value Raw parameter value.
	 * @return true|WP_Error
	 */
	public function validate_match_status( $value ) {
		if ( in_array( $value, array( 'Matched', 'Pending', 'Rejected' ), true ) ) {
			return true;
		}

		return new WP_Error(
			'rest_invalid_param',
			__( 'match_status must be Matched, Pending or Rejected.', 'taas-core' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * PATCH /applications/{application_id}: make, change or undo a match, or reject an applicant.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$status = TAAS_Match_Status::try_from_label( (string) $request['match_status'] );
				$hours  = 0;

				if ( TAAS_Match_Status::Matched === $status ) {
					if ( ! $request->has_param( 'assigned_hours' ) ) {
						return $this->missing_params( array( 'assigned_hours' ) );
					}

					$hours = (int) $request['assigned_hours'];

					if ( $hours < 1 ) {
						return $this->invalid_param( 'assigned_hours', __( 'assigned_hours must be at least 1 when setting Matched.', 'taas-core' ) );
					}
				}

				$application_id = (int) $this->path_param( $request, 'id' );
				$application    = $this->applications->find( $application_id );

				if ( null === $application ) {
					return $this->not_found();
				}

				if ( TAAS_Match_Status::Withdrawn === $application->match_status ) {
					return $this->conflict( 'taas_application_withdrawn', __( 'Withdrawn applications cannot be changed.', 'taas-core' ) );
				}

				$this->applications->set_match( $application_id, $status, $hours );

				$saved = $this->applications->find( $application_id );

				return null === $saved ? $this->not_found() : $this->prepare_item_for_response( $saved, $request );
			}
		);
	}
}
