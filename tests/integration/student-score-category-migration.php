<?php
/**
 * Verify raw scores are cleared without changing other application details.
 * Run: docker compose exec -T WordPress php /var/www/taas/tests/integration/student-score-category-migration.php
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
$wpdb->prefix = 'score_category_test_' . bin2hex( random_bytes( 4 ) ) . '_';
$profiles = TAAS_Schema::table( 'student_profiles' );
$submissions = TAAS_Schema::table( 'student_submissions' );
try {
	$wpdb->query( "CREATE TEMPORARY TABLE {$profiles} (speak_score int, toefl_ibt_score decimal(4,1), english_test_score tinyint unsigned NULL)" );
	$wpdb->query( "CREATE TEMPORARY TABLE {$submissions} (submitted_details json)" );
	$wpdb->query( "INSERT INTO {$profiles} VALUES (45, 5.5, NULL)" );
	$details = array(
		'student_name'        => 'Migration Test',
		'profile'             => array(
			'toefl_ibt_score' => 5.5,
			'speak_score'     => 45,
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
	$current = array( 'profile' => array( 'english_test_score' => 4 ) );
	$wpdb->insert( $submissions, array( 'submitted_details' => wp_json_encode( $current ) ) );
	$migrate = require TAAS_CORE_PATH . 'migrations/003-combine-english-test-scores.php';
	$migrate( $wpdb );
	$migrate( $wpdb );
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$profiles}" );
	if ( array( 'english_test_score' ) !== $columns ) {
		throw new RuntimeException( 'Retired score columns remain after migration.' );
	}
	$profile = $wpdb->get_row( "SELECT * FROM {$profiles}", ARRAY_A );
	if ( null !== $profile['english_test_score'] ) {
		throw new RuntimeException( 'Cleared score must be unanswered, not N/A.' );
	}
	unset( $details['profile']['toefl_ibt_score'], $details['profile']['speak_score'] );
	$details['profile']['english_test_score'] = null;
	$legacy = array( 'profile' => array( 'english_test_score' => null ) );
	$rows = array_map( static fn( $json ) => json_decode( $json, true ), $wpdb->get_col( "SELECT submitted_details FROM {$submissions}" ) );
	// phpcs:ignore Universal.Operators.StrictComparisons -- MySQL JSON can reorder object keys; compare the decoded objects by value.
	if ( $details != $rows[0] || $legacy != $rows[1] || $current != $rows[2] ) {
		throw new RuntimeException( 'Snapshot cleanup changed unrelated details or kept retired scores.' );
	}
	echo "PASS: Migration clears raw and legacy scores, preserves other details, and can run twice.\n";
	$wpdb->query( "DELETE FROM {$profiles}" );
	$wpdb->query( "DELETE FROM {$submissions}" );
	$migrate( $wpdb );
	echo "PASS: Migration is a no-op on a fresh schema without raw scores.\n";
} finally {
	$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$profiles}" );
	$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$submissions}" );
	$wpdb->prefix = $original_prefix;
}
