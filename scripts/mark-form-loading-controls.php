<?php
/** Mark known initial loading locks without replacing saved WordPress page edits.
 * Run with --apply and a new backup filename to apply locally.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }
$changes = array();
foreach ( array( 'student-application', 'check-application-status' ) as $slug ) {
    $id = TAAS_Pages::id( $slug );
    if ( ! $id ) { throw new RuntimeException( 'Missing page: ' . $slug ); }
    $before = get_post_field( 'post_content', $id );
    if ( str_contains( $before, 'data-application-loading-lock' ) ) { continue; }
    $after = preg_replace( '/(<fieldset\b[^>]*\bid="application-fields"[^>]*)(>)/', '$1 data-application-loading-lock$2', $before, -1, $fields );
    $after = preg_replace( '/(<button\b[^>]*\btype="submit"[^>]*\bdisabled)([^>]*>)/', '$1 data-application-loading-lock$2', $after, -1, $buttons );
    if ( 1 !== $fields || 1 !== $buttons ) { throw new RuntimeException( 'Expected one known initial fieldset and submit lock in ' . $slug ); }
    $changes[ $id ] = array( 'before' => $before, 'after' => $after );
}
echo count( $changes ) . " saved pages need loading-lock markers.\n";
if ( '--apply' !== ( $argv[1] ?? '' ) || ! $changes ) { exit( 0 ); }
$backup = $argv[2] ?? '';
if ( ! $backup || file_exists( $backup ) || false === file_put_contents( $backup, wp_json_encode( $changes, JSON_PRETTY_PRINT ), LOCK_EX ) ) {
	throw new RuntimeException( 'Supply a new writable backup filename.' );
}
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
	foreach ( $changes as $id => $change ) {
		if ( 1 !== $wpdb->update( $wpdb->posts, array( 'post_content' => $change['after'] ), array( 'ID' => $id, 'post_content' => $change['before'] ) ) ) {
			throw new RuntimeException( 'Concurrent edit or update failure; rolling back.' );
		}
	}
	$wpdb->query( 'COMMIT' );
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' );
	throw $error;
} finally {
	foreach ( array_keys( $changes ) as $id ) { clean_post_cache( $id ); }
}
echo "Loading-lock markers added; backup: $backup\n";
