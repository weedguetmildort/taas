<?php
/**
 * Replace raw exam scores with an unanswered score category.
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

return function ( wpdb $wpdb ): void {
	$profiles = TAAS_Schema::table( 'student_profiles' );
	$submissions = TAAS_Schema::table( 'student_submissions' );
	// Raw scores cannot reliably determine the new self-selected category.
	if ( false === $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET submitted_details = JSON_SET(JSON_REMOVE(submitted_details, %s, %s, %s), %s, NULL) WHERE JSON_CONTAINS_PATH(submitted_details, %s, %s, %s, %s)',
			$submissions,
			'$.profile.toefl_ibt_score',
			'$.profile.speak_score',
			'$.profile.english_test',
			'$.profile.english_test_score',
			'one',
			'$.profile.toefl_ibt_score',
			'$.profile.speak_score',
			'$.profile.english_test'
		)
	) ) {
		throw new RuntimeException( 'Could not clear retired scores in application snapshots.' );
	}
	foreach ( array( 'toefl_ibt_score', 'speak_score' ) as $column ) {
		$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $profiles, $column ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not inspect retired score columns.' );
		}
		if ( $exists && false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $profiles, $column ) ) ) {
			throw new RuntimeException( 'Could not remove a retired score column.' );
		}
	}
};
