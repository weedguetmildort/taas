<?php
/**
 * Remove obsolete language exam dates from profiles and submitted snapshots.
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

return function ( wpdb $wpdb ): void {
	$profiles = TAAS_Schema::table( 'student_profiles' );
	$submissions = TAAS_Schema::table( 'student_submissions' );

	// Only the two retired keys change; scores and other historical details stay intact.
	$result = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET submitted_details = JSON_REMOVE(submitted_details, %s, %s) WHERE JSON_CONTAINS_PATH(submitted_details, %s, %s, %s)',
			$submissions,
			'$.profile.toefl_ibt_date',
			'$.profile.speak_date',
			'one',
			'$.profile.toefl_ibt_date',
			'$.profile.speak_date'
		)
	);
	if ( false === $result ) {
		throw new RuntimeException( 'Could not remove language exam dates from submission snapshots.' );
	}

	foreach ( array( 'toefl_ibt_date', 'speak_date' ) as $column ) {
		$exists = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $profiles, $column ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not inspect language exam date columns.' );
		}
		if ( ! $exists ) {
			continue;
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $profiles, $column ) ) ) {
			throw new RuntimeException( 'Could not remove a language exam date column.' );
		}
	}
};
