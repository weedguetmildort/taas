<?php
/**
 * Allow separate online and in-person offerings without changing course IDs.
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

return function ( wpdb $wpdb ): void {
	$table = TAAS_Schema::table( 'courses' );
	$old   = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'course_offering' ) );
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Could not inspect the legacy course offering index.' );
	}
	if ( null === $old ) {
		return;
	}

	// Schema installation adds the new index before this migration runs.
	$new = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'course_offering_by_delivery' ) );
	if ( '' !== $wpdb->last_error || null === $new ) {
		throw new RuntimeException( 'Could not verify the course offering index for delivery mode.' );
	}
	if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, 'course_offering' ) ) ) {
		throw new RuntimeException( 'Could not remove the legacy course offering index.' );
	}
};
