<?php
/** Update degree labels in saved pages without replacing other WordPress edits.
 * Run with --apply and a new backup filename to save changes locally.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }
$changes = array();
foreach ( array( 'student-application', 'check-application-status' ) as $slug ) {
	$id = TAAS_Pages::id( $slug );
	if ( ! $id ) { throw new RuntimeException( 'Missing page: ' . $slug ); }
	$before = get_post_field( 'post_content', $id );
	$after = preg_replace_callback( '/<div\b[^>]*\bdata-application-ui="degreeHost"[^>]*>/', static function ( $match ) {
		$tag = preg_replace( '/\sdata-entry-label="[^"]*"/', '', $match[0] );
		foreach ( array( 'current' => 'Current Degree / Most Recent Degree', 'previous' => 'Previous Degree' ) as $kind => $label ) {
			if ( ! str_contains( $tag, 'data-' . $kind . '-entry-label=' ) ) {
				$tag = substr( $tag, 0, -1 ) . ' data-' . $kind . '-entry-label="' . esc_attr( $label ) . '">';
			}
		}
		return $tag;
	}, $before, -1, $hosts );
	if ( 1 !== $hosts ) { throw new RuntimeException( 'Expected one degree host in ' . $slug ); }
	$after = str_replace(
		array( 'List at least one degree. For a degree in progress, enter your expected graduation date.', '>Add another degree</button>', '<legend>Degree</legend>' ),
		array( 'Enter your current or most recent degree first, followed by previous degrees. For a degree in progress, enter your expected graduation date.', '>Add a previous degree</button>', '<legend>Current Degree / Most Recent Degree</legend>' ),
		$after
	);
	if ( $before !== $after ) { $changes[ $id ] = array( 'before' => $before, 'after' => $after ); }
}
echo count( $changes ) . " saved pages need degree labels.\n";
if ( '--apply' !== ( $argv[1] ?? '' ) || ! $changes ) { exit( 0 ); }
$backup = $argv[2] ?? '';
if ( ! $backup || file_exists( $backup ) || false === file_put_contents( $backup, wp_json_encode( $changes, JSON_PRETTY_PRINT ), LOCK_EX ) ) {
	throw new RuntimeException( 'Supply a new writable backup filename.' );
}
global $wpdb;
if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'Could not start the page update transaction.' ); }
try {
	foreach ( $changes as $id => $change ) {
		if ( 1 !== $wpdb->update( $wpdb->posts, array( 'post_content' => $change['after'] ), array( 'ID' => $id, 'post_content' => $change['before'] ) ) ) {
			throw new RuntimeException( 'Concurrent edit or update failure; rolling back.' );
		}
	}
	if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Could not commit the page updates.' ); }
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' );
	throw $error;
} finally {
	foreach ( array_keys( $changes ) as $id ) { clean_post_cache( $id ); }
}
echo "Degree labels updated; backup: $backup\n";
