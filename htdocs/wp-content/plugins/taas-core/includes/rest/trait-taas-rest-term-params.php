<?php
/**
 * Term handling for REST controllers.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * The ?term=&year= parameters shared by routes that work on one term.
 */
trait TAAS_REST_Term_Params {

	/**
	 * Validate a term parameter against TAAS_Semester.
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
	 * Route args for a term and year.
	 *
	 * @param bool $required Whether the request must send them.
	 * @return array<string, array>
	 */
	protected function term_args( bool $required = false ): array {
		return array(
			'term' => array(
				'description'       => __( 'Semester, e.g. Fall, Spring, SummerA, SummerB or SummerC.', 'taas-core' ),
				'type'              => 'string',
				'required'          => $required,
				'validate_callback' => array( $this, 'validate_term' ),
			),
			'year' => array(
				'description' => __( 'Four-digit year, e.g. 2026.', 'taas-core' ),
				'type'        => 'integer',
				'required'    => $required,
				'minimum'     => 1901,
				'maximum'     => 2155,
			),
		);
	}

	/**
	 * The term a request asks for, defaulting to the current application cycle's.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array{0: TAAS_Semester, 1: int}|WP_Error A 400 if only one of term and year was sent, or a 404 if neither was and no cycle is set.
	 * @throws RuntimeException If the query fails.
	 */
	protected function requested_term( $request ) {
		$has_term = $request->has_param( 'term' );
		$has_year = $request->has_param( 'year' );

		if ( $has_term !== $has_year ) {
			return $this->missing_params( array( $has_term ? 'year' : 'term' ) );
		}

		if ( $has_term ) {
			return array( TAAS_Semester::try_from_label( (string) $request['term'] ), (int) $request['year'] );
		}

		$cycle = ( new TAAS_Application_Cycle_Repository() )->current();

		return null === $cycle ? $this->no_current_cycle() : array( $cycle->cycle_semester, $cycle->cycle_year );
	}

	/**
	 * A 404 for when no application cycle has been set.
	 *
	 * @return WP_Error
	 */
	protected function no_current_cycle(): WP_Error {
		return new WP_Error(
			'taas_no_current_cycle',
			__( 'No application cycle has been set.', 'taas-core' ),
			array( 'status' => 404 )
		);
	}
}
