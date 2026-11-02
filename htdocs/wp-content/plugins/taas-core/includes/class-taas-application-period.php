<?php
/**
 * Application-period configuration shared with the administration interface.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * No implicit term or open period: administrators must configure these values.
 */
final class TAAS_Application_Period {

	public const OPTION = 'taas_application_period';

	/**
	 * Read the configured semester, year, manual open flag and optional UTC deadline.
	 *
	 * TODO: Confirm this configuration contract and closure precedence with Team 2.
	 * The admin UI is not implemented here. Both manual closure and a passed
	 * deadline close the period; no grace behavior is implemented.
	 *
	 * @return array{
	 *   semester: int,
	 *   year: int,
	 *   label: string,
	 *   is_open: bool,
	 *   closes_at: int|null
	 * }|WP_Error Validated configuration, or a 503 error when unconfigured.
	 *            is_open includes both the manual flag and the deadline check.
	 *            closes_at is a UTC Unix timestamp; null means no deadline.
	 */
	public static function current(): array|WP_Error {
		$value = get_option( self::OPTION, null );
		if ( ! is_array( $value )
			|| ! is_int( $value['semester'] ?? null )
			|| null === TAAS_Semester::tryFrom( $value['semester'] )
			|| ! is_int( $value['year'] ?? null )
			|| $value['year'] < 1901 || $value['year'] > 2155
			|| ! is_bool( $value['is_open'] ?? null )
			|| ( isset( $value['closes_at'] ) && ( ! is_int( $value['closes_at'] ) || $value['closes_at'] < 1 ) )
		) {
			return new WP_Error( 'taas_period_unconfigured', 'The application period has not been configured. Please contact the administrator.', array( 'status' => 503 ) );
		}
		$deadline = $value['closes_at'] ?? null;
		return array(
			'semester'  => $value['semester'],
			'year'      => $value['year'],
			'label'     => TAAS_Semester::from( $value['semester'] )->label() . ' ' . $value['year'],
			'is_open'   => $value['is_open'] && ( null === $deadline || time() < $deadline ),
			'closes_at' => $deadline,
		);
	}
}
