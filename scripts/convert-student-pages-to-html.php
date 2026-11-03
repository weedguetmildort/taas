<?php
/** Flatten remaining standard WordPress blocks into plain HTML.
 * Preview with no arguments; apply with --apply followed by a new backup filename.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }
$changes = array( 'sources' => array(), 'pages' => array() );
foreach ( array( 'student-application', 'check-application-status', 'front-page' ) as $slug ) {
	$path = TAAS_CORE_PATH . 'pages/' . $slug . '.html';
	$before = file_get_contents( $path );
	if ( has_blocks( $before ) ) {
		$changes['sources'][ $path ] = array( 'before' => $before, 'after' => trim( do_blocks( $before ) ) . "\n" );
	}
	$id = TAAS_Pages::id( $slug );
	if ( ! $id ) { continue; }
	$before = get_post_field( 'post_content', $id );
	if ( has_blocks( $before ) ) {
		$changes['pages'][ $id ] = array( 'before' => $before, 'after' => trim( do_blocks( $before ) ) . "\n" );
	}
}
echo count( $changes['sources'] ) . ' source pages, ' . count( $changes['pages'] ) . ' saved pages to flatten.' . PHP_EOL;
if ( '--apply' !== ( $argv[1] ?? '' ) || ! ( $changes['sources'] || $changes['pages'] ) ) { exit( 0 ); }
$backup = $argv[2] ?? '';
if ( ! $backup || file_exists( $backup ) || false === file_put_contents( $backup, wp_json_encode( $changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), LOCK_EX ) ) {
	throw new RuntimeException( 'Supply a new writable backup filename.' );
}
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
	foreach ( $changes['pages'] as $id => $change ) {
		if ( 1 !== $wpdb->update( $wpdb->posts, array( 'post_content' => $change['after'] ), array( 'ID' => $id, 'post_content' => $change['before'] ) ) ) {
			throw new RuntimeException( 'Concurrent page edit; conversion rolled back.' );
		}
	}
	foreach ( $changes['sources'] as $path => $change ) {
		if ( file_get_contents( $path ) !== $change['before'] || false === file_put_contents( $path, $change['after'], LOCK_EX ) ) {
			throw new RuntimeException( 'Cannot update source: ' . $path );
		}
	}
	$wpdb->query( 'COMMIT' );
} catch ( Throwable $error ) {
	$wpdb->query( 'ROLLBACK' );
	foreach ( $changes['sources'] as $path => $change ) {
		if ( file_get_contents( $path ) === $change['after'] ) { file_put_contents( $path, $change['before'], LOCK_EX ); }
	}
	throw $error;
} finally {
	foreach ( array_keys( $changes['pages'] ) as $id ) { clean_post_cache( $id ); }
}
echo 'Flattened; backup: ' . $backup . PHP_EOL;
