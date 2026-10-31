<?php
/** Non-browser checks for plain HTML and WordPress-owned student pages. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }

function taas_editor_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}

taas_editor_check( ! function_exists( 'taas_student_editor_block_definitions' ) && ! function_exists( 'taas_student_render_editor_block' ), 'Student presentation needs no custom PHP block definitions or renderers' );

foreach ( array( 'student-application', 'check-application-status' ) as $slug ) {
	$source = file_get_contents( TAAS_CORE_PATH . 'pages/' . $slug . '.html' );
	$page = get_post( TAAS_Pages::id( $slug ) );
	taas_editor_check( ! has_blocks( $source ) && ! str_contains( $source, '<!-- wp:' ), $slug . ' source is plain HTML without WordPress blocks' );
	taas_editor_check( use_block_editor_for_post( $page ), $slug . ' uses the modern WordPress editor' );
	taas_editor_check( ! str_contains( $source, '[taas_student_application_form' ), $slug . ' does not use the legacy form shortcode' );
	taas_editor_check( ! has_blocks( $source ), $slug . ' owns ordinary input markup in HTML' );
	$html = $source;
	taas_editor_check( 1 === substr_count( $html, 'id="student-application-form"' ) && ! str_contains( $html, '{{' ), $slug . ' renders one complete form' );
	$dom = new DOMDocument();
	@$dom->loadHTML( $html );
	$xpath = new DOMXPath( $dom );
	foreach ( array( 'ufid', 'ufEmail', 'countryOfOrigin', 'startSemester', 'collegeStatus', 'phone', 'eap5836Status', 'eap5837Status', 'taHours', 'englishScore', 'additionalInfo', 'resumeUrl' ) as $name ) {
		taas_editor_check( 1 === $xpath->query( '//form//*[@name="' . $name . '"]' )->length, $slug . ' retains ' . $name . ' inside the form' );
	}
}

foreach ( array( 'student-application', 'check-application-status', 'front-page' ) as $slug ) {
	$saved = get_post_field( 'post_content', TAAS_Pages::id( $slug ) );
	taas_editor_check( str_starts_with( $saved, '<!-- wp:html -->' ) && 1 === substr_count( $saved, '<!-- wp:' ), $slug . ' saves its HTML in one standard Custom HTML block' );
}

// All writes are rolled back. Other HTTP requests cannot see the temporary edits.
global $wpdb;
$ids = array();
$wpdb->query( 'START TRANSACTION' );
try {
	foreach ( array( 'student-application', 'check-application-status', 'front-page' ) as $slug ) {
		$id = TAAS_Pages::id( $slug );
		$ids[] = $id;
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<!-- wp:paragraph --><p>Editor preservation fixture</p><!-- /wp:paragraph -->' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
	}
	TAAS_Pages::sync_content();
	foreach ( $ids as $id ) {
		taas_editor_check( str_contains( get_post_field( 'post_content', $id ), 'Editor preservation fixture' ), 'Automatic sync preserves WordPress edits on page ' . $id );
	}
	TAAS_Pages::sync_content( true, array( 'student-application' ) );
	taas_editor_check( trim( do_blocks( get_post_field( 'post_content', $ids[0] ) ) ) === trim( file_get_contents( TAAS_CORE_PATH . 'pages/student-application.html' ) ), 'Explicit import can restore the selected source page' );
	taas_editor_check( str_contains( get_post_field( 'post_content', $ids[1] ), 'Editor preservation fixture' ) && str_contains( get_post_field( 'post_content', $ids[2] ), 'Editor preservation fixture' ), 'Explicit import leaves other WordPress pages untouched' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	foreach ( $ids as $id ) { clean_post_cache( $id ); }
}
