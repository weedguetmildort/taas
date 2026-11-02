<?php
/**
 * REST controller for the application cycle.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/application-cycle.
 *
 *   GET   /application-cycle   the current cycle, for any signed-in user in any stage
 *   PUT   /application-cycle   start a new cycle, replacing the current one
 *   PATCH /application-cycle   change the dates
 *
 * Only the admin may write. The dates alone decide the active stage, so the admin
 * starts, ends or reopens a stage by changing them. One stage ending never starts
 * the next: only that stage's own start_at does.
 */
final class TAAS_REST_Application_Cycle_Controller extends TAAS_REST_Controller {

	use TAAS_REST_Term_Params;

	/**
	 * Application cycle table.
	 *
	 * @var TAAS_Application_Cycle_Repository
	 */
	private TAAS_Application_Cycle_Repository $cycles;

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( 'application-cycle' );

		$this->cycles = new TAAS_Application_Cycle_Repository();
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$admin_only = TAAS_Auth::require_role( TAAS_Auth::ROLE_ADMIN );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => TAAS_Auth::require_authenticated(),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'replace_item' ),
					'permission_callback' => $admin_only,
					'args'                => $this->term_args( true ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $admin_only,
				),
			)
		);
	}

	/**
	 * GET /application-cycle: the current cycle.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		return $this->respond(
			function () {
				$cycle = $this->cycles->current();

				return null === $cycle ? $this->no_current_cycle() : $cycle->to_response();
			}
		);
	}

	/**
	 * PUT /application-cycle: start a new cycle, replacing the current one.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function replace_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$dates = $request->has_param( 'stage_dates' ) ? $this->parse_stage_dates( $request['stage_dates'] ) : array();

				if ( is_wp_error( $dates ) ) {
					return $dates;
				}

				$schedule_error = $this->schedule_error( $dates );

				if ( null !== $schedule_error ) {
					return $schedule_error;
				}

				$cycle = new TAAS_Application_Cycle(
					TAAS_Semester::try_from_label( (string) $request['term'] ),
					(int) $request['year'],
					$dates
				);

				$this->cycles->save( $cycle );

				return $cycle->to_response();
			}
		);
	}

	/**
	 * PATCH /application-cycle: change the dates of the stages named.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				if ( ! $request->has_param( 'stage_dates' ) ) {
					return $this->missing_params( array( 'stage_dates' ) );
				}

				$current = $this->cycles->current();

				if ( null === $current ) {
					return $this->no_current_cycle();
				}

				$changes = $this->parse_stage_dates( $request['stage_dates'] );

				if ( is_wp_error( $changes ) ) {
					return $changes;
				}

				$dates = array_filter( $changes + $current->stage_dates );

				$schedule_error = $this->schedule_error( $dates );

				if ( null !== $schedule_error ) {
					return $schedule_error;
				}

				$cycle = new TAAS_Application_Cycle(
					$current->cycle_semester,
					$current->cycle_year,
					$dates
				);

				$this->cycles->save( $cycle );

				return $cycle->to_response();
			}
		);
	}

	/**
	 * Turn a request's stage_dates into the form TAAS_Application_Cycle holds.
	 *
	 * Only the stages named are returned. A stage with no dates comes back as null.
	 *
	 * @param mixed $value Raw stage_dates value.
	 * @return array<string, array|null>|WP_Error
	 */
	private function parse_stage_dates( mixed $value ) {
		if ( ! is_array( $value ) ) {
			return $this->invalid_param( 'stage_dates', __( 'stage_dates must be an object keyed by stage.', 'taas-core' ) );
		}

		$dates = array();

		foreach ( $value as $label => $entry ) {
			if ( null === TAAS_Cycle_Stage::try_from_label( (string) $label ) ) {
				return $this->invalid_param( 'stage_dates', __( 'stage_dates must be keyed by stage.', 'taas-core' ) );
			}

			if ( null === $entry ) {
				$dates[ $label ] = null;
				continue;
			}

			if ( ! is_array( $entry ) || array( 'end_at', 'start_at' ) !== self::sorted_keys( $entry ) ) {
				return $this->invalid_param( 'stage_dates', __( 'Each stage needs both start_at and end_at, or null to clear its dates.', 'taas-core' ) );
			}

			$start_at = self::parse_date( $entry['start_at'] );
			$end_at   = self::parse_date( $entry['end_at'] );

			if ( false === $start_at || false === $end_at ) {
				return $this->invalid_param( 'stage_dates', __( 'start_at and end_at must be UTC timestamps like 2026-11-16T04:59:59Z, or null.', 'taas-core' ) );
			}

			$dates[ $label ] = null === $start_at && null === $end_at ? null : array(
				'start_at' => $start_at,
				'end_at'   => $end_at,
			);
		}

		return $dates;
	}

	/**
	 * An array's keys in sorted order.
	 *
	 * @param array $entry Array to read.
	 * @return array
	 */
	private static function sorted_keys( array $entry ): array {
		$keys = array_keys( $entry );
		sort( $keys );

		return $keys;
	}

	/**
	 * Parse one date from stage_dates.
	 *
	 * @param mixed $value Raw date value.
	 * @return DateTimeImmutable|null|false False if the value is not valid.
	 */
	private static function parse_date( mixed $value ) {
		if ( null === $value ) {
			return null;
		}

		return ( is_string( $value ) ? TAAS_Application_Cycle::parse_api_date( $value ) : null ) ?? false;
	}

	/**
	 * Check every stage ends after it starts and no two stages overlap.
	 *
	 * Only stages with both dates can overlap, since a missing date states no
	 * time to compare. A moment shared by two stages counts as overlapping.
	 *
	 * @param array $dates Dates by stage label; null entries are skipped.
	 * @return WP_Error|null
	 */
	private function schedule_error( array $dates ): ?WP_Error {
		$bounded = array();

		foreach ( array_filter( $dates ) as $label => $entry ) {
			if ( null === $entry['start_at'] || null === $entry['end_at'] ) {
				continue;
			}

			if ( $entry['end_at'] < $entry['start_at'] ) {
				/* translators: %s: stage name. */
				return $this->invalid_param( 'stage_dates', sprintf( __( '%s has an end_at before its start_at.', 'taas-core' ), $label ) );
			}

			foreach ( $bounded as $other_label => $other ) {
				if ( $entry['start_at'] <= $other['end_at'] && $other['start_at'] <= $entry['end_at'] ) {
					/* translators: 1: stage name, 2: stage name. */
					return $this->invalid_param( 'stage_dates', sprintf( __( 'The dates of %1$s and %2$s overlap.', 'taas-core' ), $other_label, $label ) );
				}
			}

			$bounded[ $label ] = $entry;
		}

		return null;
	}
}
