<?php
/** Add status-row templates to saved WordPress content, preserving all other edits.
 * Run with --apply and a new backup filename to apply.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }
$id = TAAS_Pages::id( 'check-application-status' );
if ( ! $id ) { throw new RuntimeException( 'Missing status page.' ); }
$before = get_post_field( 'post_content', $id );
$changes = array();
if ( ! str_contains( $before, 'data-status-template=' ) ) {
    $source = file_get_contents( TAAS_CORE_PATH . 'pages/check-application-status.html' );
    preg_match_all( '/<template data-status-template="[^"]+">.*?<\/template>/s', $source, $matches );
    if ( 2 !== count( $matches[0] ) ) { throw new RuntimeException( 'Missing source templates.' ); }
    $templates = implode( "\n", $matches[0] ) . "\n";
    $after = preg_replace_callback( '/<div\b[^>]*\bid="status-table-wrapper"[^>]*>/', static fn( $match ) => $templates . $match[0], $before, -1, $count );
    if ( 1 !== $count ) { throw new RuntimeException( 'Expected one status table.' ); }
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
echo "Status templates added; backup: $backup\n";
