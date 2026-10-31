<?php
/** Add course HTML templates to saved student pages while preserving other content.
 * Preview: docker compose exec -T wordpress php /var/www/taas/scripts/add-course-templates.php
 * Apply: append --apply /var/www/taas/real-data/course-templates-before.json
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }
$changes = array();
foreach ( array( 'student-application', 'check-application-status' ) as $slug ) {
	$id = TAAS_Pages::id( $slug );
	if ( ! $id ) { throw new RuntimeException( 'Missing page: ' . $slug ); }
	$before = get_post_field( 'post_content', $id );
	if ( str_contains( $before, 'data-course-template=' ) ) { continue; }
	$source = file_get_contents( TAAS_CORE_PATH . 'pages/' . $slug . '.html' );
	preg_match_all( '/<template data-course-template="[^"]+">.*?<\/template>/s', $source, $matches );
	if ( 5 !== count( $matches[0] ) ) { throw new RuntimeException( 'Missing source templates.' ); }
	$templates = implode( "\n", $matches[0] ) . "\n";
	$after = preg_replace_callback( '/<div\b[^>]*\bid="available-course-list"[^>]*>/', static fn( $match ) => $templates . $match[0], $before, -1, $count );
	if ( 1 !== $count ) { throw new RuntimeException( 'Expected one course list in ' . $slug ); }
	$changes[ $id ] = array( 'before' => $before, 'after' => $after );
}
echo count( $changes ) . " saved pages need templates.\n";
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
echo "Templates added; backup: $backup\n";
