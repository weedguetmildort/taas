<?php
/**
 * Application-period checks using option filters; no configuration is saved.
 *
 * @package TAAS_Tests
 */

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, WordPress.Security.EscapeOutput, WordPress.DB.DirectDatabaseQuery, WordPress.WP.GlobalVariablesOverride, WordPress.PHP.YodaConditions, Universal.Operators.StrictComparisons
// CLI checks intentionally use direct SQL, console diagnostics and JSON-semantic comparisons.

declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 ); }
require '/var/www/html/wp-load.php';

function taas_period_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
}

function taas_period_invalid(): void {
	$result = TAAS_Application_Period::current();
	taas_period_check( is_wp_error( $result ), 'Invalid configuration must return a WP_Error.' );
	taas_period_check( 'taas_period_unconfigured' === $result->get_error_code(), 'Invalid configuration returned the wrong error code.' );
	taas_period_check( array( 'status' => 503 ) === $result->get_error_data(), 'Invalid configuration must return status 503.' );
}

$valid = array(
	'semester'  => 1,
	'year'      => 2026,
	'is_open'   => true,
	'closes_at' => null,
);
$configuration = $valid;
$filter = static function () use ( &$configuration ) {
	return $configuration;
};
add_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $filter );

try {
	foreach ( array( null, true, 'invalid', 1, array() ) as $configuration ) {
		taas_period_invalid();
	}
	foreach ( array( 'semester', 'year', 'is_open' ) as $field ) {
		$configuration = $valid;
		unset( $configuration[ $field ] );
		taas_period_invalid();
	}
	echo "PASS: Missing and malformed configuration returns a 503 error\n";

	$invalid_fields = array(
		'semester'  => array( 0, 6, '1', 1.0, true, null, array() ),
		'year'      => array( 1900, 2156, '2026', 2026.0, true, null, array() ),
		'is_open'   => array( 0, 1, 'true', 'false', null, array() ),
		'closes_at' => array( 0, -1, '1234567890', 1234567890.0, true, false, array() ),
	);
	foreach ( $invalid_fields as $field => $values ) {
		foreach ( $values as $invalid ) {
			$configuration = $valid;
			$configuration[ $field ] = $invalid;
			taas_period_invalid();
		}
	}
	echo "PASS: Strict field types and supported ranges\n";

	$labels = array(
		1 => 'Fall',
		2 => 'Spring',
		3 => 'Summer A',
		4 => 'Summer B',
		5 => 'Summer C',
	);
	foreach ( $labels as $semester => $label ) {
		foreach ( array( 1901, 2155 ) as $year ) {
			$configuration = array_replace(
				$valid,
				array(
					'semester' => $semester,
					'year'     => $year,
				)
			);
			taas_period_check(
				array(
					'semester'  => $semester,
					'year'      => $year,
					'label'     => $label . ' ' . $year,
					'is_open'   => true,
					'closes_at' => null,
				) === TAAS_Application_Period::current(),
				'The normalized period or label is incorrect.'
			);
		}
	}
	echo "PASS: All semester labels and year boundaries\n";

	$configuration = $valid;
	unset( $configuration['closes_at'] );
	$result = TAAS_Application_Period::current();
	taas_period_check( true === $result['is_open'] && null === $result['closes_at'], 'An omitted deadline must allow an open period.' );
	$configuration = $valid;
	taas_period_check( true === TAAS_Application_Period::current()['is_open'], 'An explicit null deadline must allow an open period.' );
	echo "PASS: Omitted and null deadlines\n";

	foreach ( array( null, time() + 3600 ) as $deadline ) {
		$configuration = array_replace(
			$valid,
			array(
				'is_open'   => false,
				'closes_at' => $deadline,
			)
		);
		taas_period_check( false === TAAS_Application_Period::current()['is_open'], 'Manual closure must override an absent or future deadline.' );
	}
	echo "PASS: Manual closure overrides the deadline\n";

	foreach ( array(
		time() - 3600 => false,
		time()        => false,
		time() + 3600 => true,
	) as $deadline => $expected_open ) {
		$configuration = array_replace( $valid, array( 'closes_at' => $deadline ) );
		$result = TAAS_Application_Period::current();
		taas_period_check( $expected_open === $result['is_open'], 'A past, reached or future deadline produced the wrong open state.' );
		taas_period_check( $deadline === $result['closes_at'], 'The configured UTC timestamp must be preserved.' );
	}
	echo "PASS: Past, reached and future deadlines\n";
} finally {
	remove_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $filter );
}
