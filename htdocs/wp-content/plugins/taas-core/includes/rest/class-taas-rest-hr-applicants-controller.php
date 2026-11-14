<?php
/**
 * Read applicant summaries and submitted details for the current HR review.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/** Serve applicants and persist HR review decisions for the current cycle. */
final class TAAS_REST_HR_Applicants_Controller extends TAAS_REST_Controller {

	/** Create the controller. */
	public function __construct() {
		parent::__construct( 'hr/applicants' );
	}

	/** Register applicant and review routes. */
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
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/decisions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_decisions' ),
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
				$rows       = ( new TAAS_HR_Review_Repository() )->applicants_for_cycle( $cycle );
				$applicants = array();
				$progress   = array(
					'total'    => count( $rows ),
					'reviewed' => 0,
					'approved' => 0,
				);
				foreach ( $rows as $row ) {
					$applicants[]          = self::summary( $row['submission'], $row['decision'], $row['version'], $row['comment'] );
					$progress['reviewed'] += null !== $row['decision'] ? 1 : 0;
					$progress['approved'] += 'approve' === $row['decision'] ? 1 : 0;
				}
				return self::private_response(
					array(
						'term'       => $cycle->cycle_semester->label(),
						'year'       => $cycle->cycle_year,
						'applicants' => $applicants,
						'progress'   => $progress,
					)
				);
			}
		);
	}

	/**
	 * Save all submitted decisions or report stale versions without changing any.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_decisions( WP_REST_Request $request ) {
		$batch = $this->parse_decisions( $request->get_json_params()['decisions'] ?? null );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		return $this->with_open_cycle( fn( TAAS_Application_Cycle $cycle ) => $this->save_batch( $cycle, $batch ), true );
	}

	/**
	 * Validate the decision payload and sort it in lock acquisition order.
	 *
	 * @param mixed $decisions Submitted list.
	 * @return array<int, array{decision: string, version: int, comment?: ?string}>|WP_Error
	 */
	private function parse_decisions( mixed $decisions ) {
		if ( ! is_array( $decisions ) || ! array_is_list( $decisions ) || ! $decisions ) {
			return $this->invalid_param( 'decisions', __( 'Provide a nonempty list of review decisions.', 'taas-core' ) );
		}
		$batch = array();
		foreach ( $decisions as $entry ) {
			$id       = $entry['submission_id'] ?? null;
			$decision = $entry['decision'] ?? null;
			$version  = $entry['version'] ?? null;
			if ( ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]*$/D', $id ) || (string) (int) $id !== $id || isset( $batch[ $id ] )
				|| ! in_array( $decision, array( 'approve', 'reject' ), true ) || ! is_int( $version ) || $version < 0 || $version > 4294967295 ) {
				return $this->invalid_param( 'decisions', __( 'Each decision needs a unique submission ID, a decision, and the expected version.', 'taas-core' ) );
			}
			$batch[ (int) $id ] = array(
				'decision' => $decision,
				'version'  => $version,
			);
			if ( array_key_exists( 'comment', $entry ) ) {
				$comment = $entry['comment'];
				if ( null !== $comment && ( ! is_string( $comment ) || ! preg_match( '/\A.{0,1000}\z/us', $comment ) ) ) {
					return $this->invalid_param( 'decisions', __( 'A review comment must be text of at most 1,000 characters or null.', 'taas-core' ) );
				}
				$comment = is_string( $comment ) ? trim( str_replace( array( "\r\n", "\r" ), "\n", $comment ) ) : '';
				$batch[ (int) $id ]['comment'] = '' === $comment ? null : $comment;
			}
		}
		ksort( $batch );
		return $batch;
	}

	/**
	 * Lock the submissions and validate the whole batch before writing.
	 *
	 * @param TAAS_Application_Cycle                                               $cycle Active cycle.
	 * @param array<int, array{decision: string, version: int, comment?: ?string}> $batch Validated, sorted decisions.
	 * @return WP_REST_Response|WP_Error
	 */
	private function save_batch( TAAS_Application_Cycle $cycle, array $batch ) {
		$repository = new TAAS_HR_Review_Repository();
		$reviews    = array();
		$conflicts  = array();
		// Take all parent locks before the first consistent read of review rows.
		foreach ( array_keys( $batch ) as $id ) {
			if ( ! $repository->lock_submission( $id, $cycle ) ) {
				return $this->not_found();
			}
		}
		foreach ( $batch as $id => $change ) {
			$current        = $repository->current_review( $id );
			$reviews[ $id ] = array( 'submission_id' => (string) $id ) + $current;
			if ( $change['version'] !== $current['version'] ) {
				$conflicts[] = $reviews[ $id ];
			}
		}
		$stage = $cycle->active_stage();
		if ( TAAS_Cycle_Stage::Hr_Review !== $stage ) {
			return self::review_closed( $stage );
		}
		if ( $conflicts ) {
			return self::private_response(
				array(
					'code'      => 'taas_review_conflict',
					'conflicts' => $conflicts,
					'progress'  => $repository->progress( $cycle ),
				),
				409
			);
		}
		$reviewer_id = TAAS_Auth::current_user()['wp_user_id'] ?? null;
		foreach ( $batch as $id => $change ) {
			$comment = array_key_exists( 'comment', $change ) ? $change['comment'] : $reviews[ $id ]['comment'];
			if ( $change['decision'] === $reviews[ $id ]['decision'] && $comment === $reviews[ $id ]['comment'] ) {
				continue;
			}
			$repository->save_review( $id, $change['decision'], $change['version'], $reviewer_id, $comment );
			$reviews[ $id ]['decision'] = $change['decision'];
			$reviews[ $id ]['comment']  = $comment;
			++$reviews[ $id ]['version'];
		}
		return self::private_response(
			array(
				'reviews'  => array_values( $reviews ),
				'progress' => $repository->progress( $cycle ),
			)
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
	 * @param callable $work     Callback that receives the current cycle.
	 * @param bool     $for_save Hold the cycle stable until a write commits.
	 * @return WP_REST_Response|WP_Error
	 */
	private function with_open_cycle( callable $work, bool $for_save = false ) {
		$check = static function () use ( $work, $for_save ) {
			$cycles = new TAAS_Application_Cycle_Repository();
			$cycle  = $for_save ? $cycles->current_for_share() : $cycles->current();
			$stage  = $cycle?->active_stage();
			return TAAS_Cycle_Stage::Hr_Review === $stage ? $work( $cycle ) : self::review_closed( $stage );
		};
		return $this->respond( static fn() => $for_save ? TAAS_Transaction::run( $check ) : $check() );
	}

	/**
	 * Report the active stage when HR review is closed.
	 *
	 * @param TAAS_Cycle_Stage|null $stage Active stage, if any.
	 * @return WP_Error
	 */
	private static function review_closed( ?TAAS_Cycle_Stage $stage ): WP_Error {
		return new WP_Error(
			'taas_stage_closed',
			__( 'HR review is closed.', 'taas-core' ),
			array(
				'status' => 403,
				'stage'  => $stage?->label(),
			)
		);
	}

	/**
	 * Mark applicant responses as private and non-cacheable.
	 *
	 * @param array $data   Response body.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function private_response( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status, array( 'Cache-Control' => 'private, no-store' ) );
	}

	/**
	 * Project the submitted snapshot onto the applicant table fields.
	 *
	 * @param TAAS_Student_Submission $submission Saved term snapshot.
	 * @param string|null             $decision   Saved HR decision.
	 * @param int                     $version    Saved decision version.
	 * @param string|null             $comment    Private HR comment.
	 * @return array<string, string|float|int|null>
	 */
	private static function summary( TAAS_Student_Submission $submission, ?string $decision, int $version, ?string $comment ): array {
		$details = $submission->submitted_details;
		$degree  = $details['academic_background'][0] ?? array();
		$profile = $details['profile'] ?? array();
		$type    = TAAS_Enrollment_Status::tryFrom( (int) ( $profile['enrollment_status'] ?? -1 ) );
		$score   = TAAS_English_Test_Score::tryFrom( (int) ( $profile['english_test_score'] ?? 0 ) );
		return array(
			'submission_id'      => (string) $submission->submission_id,
			'decision'           => $decision,
			'version'            => $version,
			'comment'            => $comment,
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
			'advisor_name'       => TAAS_Enrollment_Status::Phd === $type ? ( $profile['advisor_name'] ?? null ) : null,
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
