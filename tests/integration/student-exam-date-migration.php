<?php
/**
 * Verify retired exam dates are removed without changing scores or other details.
 * Run: docker compose exec -T WordPress php /var/www/taas/tests/integration/student-exam-date-migration.php
 * Uses connection-local temporary tables, never existing application records.
 *
 * @package TAAS_Tests
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride, WordPress.Security.EscapeOutput

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development mode required.' );
}

$original_prefix = $wpdb->prefix;
$wpdb->prefix = 'exam_date_test_' . bin2hex( random_bytes( 4 ) ) . '_';
$profiles = TAAS_Schema::table( 'student_profiles' );
$submissions = TAAS_Schema::table( 'student_submissions' );
try {
	$wpdb->query( "CREATE TEMPORARY TABLE {$profiles} (speak_score int, toefl_ibt_score decimal(4,1), speak_date date, toefl_ibt_date date)" );
	$wpdb->query( "CREATE TEMPORARY TABLE {$submissions} (submitted_details json)" );
	$wpdb->query( "INSERT INTO {$profiles} VALUES (45, 5.5, '2026-01-10', '2026-02-10')" );
	$details = array(
		'student_name'        => 'Migration Test',
		'profile'             => array(
			'toefl_ibt_score' => 5.5,
			'speak_score'     => 45,
			'toefl_ibt_date'  => '2026-02-10',
			'speak_date'      => null,
			'fws_eligibility' => 'no',
		),
		'academic_background' => array( array( 'grad_date' => '2025-05-01' ) ),
	);
	$wpdb->insert( $submissions, array( 'submitted_details' => wp_json_encode( $details ) ) );
	$legacy = array(
		'profile' => array(
			'english_test' => array(
				'type'  => 'TOEFL',
				'score' => 5.5,
			),
		),
	);
	$wpdb->insert( $submissions, array( 'submitted_details' => wp_json_encode( $legacy ) ) );
	$migrate = require TAAS_CORE_PATH . 'migrations/002-remove-language-exam-dates.php';
	$migrate( $wpdb );
	$migrate( $wpdb );
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$profiles}" );
	if ( array( 'speak_score', 'toefl_ibt_score' ) !== $columns ) {
		throw new RuntimeException( 'Exam date columns remain after migration.' );
	}
	$profile = $wpdb->get_row( "SELECT * FROM {$profiles}", ARRAY_A );
	if ( 45 !== (int) $profile['speak_score'] || 5.5 !== (float) $profile['toefl_ibt_score'] ) {
		throw new RuntimeException( 'Migration changed profile scores.' );
	}
	unset( $details['profile']['toefl_ibt_date'], $details['profile']['speak_date'] );
	$rows = array_map( static fn( $json ) => json_decode( $json, true ), $wpdb->get_col( "SELECT submitted_details FROM {$submissions}" ) );
	// phpcs:ignore Universal.Operators.StrictComparisons -- MySQL JSON can reorder object keys; compare the decoded objects by value.
	if ( $details != $rows[0] || $legacy != $rows[1] ) {
		throw new RuntimeException( 'Snapshot cleanup changed unrelated details or kept retired dates.' );
	}
	echo "PASS: Migration removes both columns and snapshot keys, preserves scores and unrelated details, and can run twice.\n";
	$wpdb->query( "DELETE FROM {$profiles}" );
	$wpdb->query( "DELETE FROM {$submissions}" );
	$migrate( $wpdb );
	echo "PASS: Migration is a no-op on a fresh schema without dates.\n";
} finally {
	$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$profiles}" );
	$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$submissions}" );
	$wpdb->prefix = $original_prefix;
}
