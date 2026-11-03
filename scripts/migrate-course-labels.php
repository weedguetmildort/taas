<?php
/** Move course label overrides into saved HTML templates, preserving page edits.
 * Run with --apply and a new backup filename to save changes.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }
$changes = array();
foreach ( array( 'student-application', 'check-application-status' ) as $slug ) {
    $id = TAAS_Pages::id( $slug );
    if ( ! $id ) { throw new RuntimeException( 'Missing page: ' . $slug ); }
    $before = get_post_field( 'post_content', $id );
    $copy = array();
    $after = preg_replace_callback( '/\sdata-course-copy=([\'"])(.*?)\1/s', static function ( $match ) use ( &$copy ) {
        $copy = json_decode( html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true, 512, JSON_THROW_ON_ERROR );
        return '';
    }, $before );
    $after = preg_replace_callback( '/(<(?:span|option)\b[^>]*?) data-course-label="([^"]+)"([^>]*?)>([^<]*)(<\/(?:span|option)>)/', static function ( $match ) use ( $copy ) {
        $text = ! empty( $copy[ $match[2] ] ) ? esc_html( $copy[ $match[2] ] ) : $match[4];
        return $match[1] . $match[3] . '>' . $text . $match[5];
    }, $after );
    if ( ! str_contains( $after, 'data-course-template="emptyRankings"' ) ) {
        $templates = '';
        foreach ( array( 'emptyRankings' => 'Select one or more courses to rank them here.', 'readOnlyEmptyRankings' => 'No active course preferences remain for this term.' ) as $name => $fallback ) {
            $templates .= '<template data-course-template="' . $name . '"><p class="empty-course-rankings">' . esc_html( $copy[ $name ] ?? $fallback ) . '</p></template>' . "\n";
        }
        $after = str_replace( '<template data-course-template="header">', $templates . '<template data-course-template="header">', $after, $count );
        if ( 1 !== $count ) { throw new RuntimeException( 'Expected one header template: ' . $slug ); }
    }
    if ( $before !== $after ) { $changes[ $id ] = array( 'before' => $before, 'after' => $after ); }
}
echo count( $changes ) . " saved pages need label cleanup.\n";
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
echo "Labels migrated; backup: $backup\n";
