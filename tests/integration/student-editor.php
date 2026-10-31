<?php
/** Non-browser checks for editor blocks and preservation of WordPress content. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }

function taas_editor_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}

$registry = WP_Block_Type_Registry::get_instance();
foreach ( array_keys( taas_student_editor_block_definitions() ) as $slug ) {
	taas_editor_check( $registry->is_registered( 'taas/' . $slug ), $slug . ' is registered for WordPress' );
}

foreach ( array( 'student-application', 'check-application-status' ) as $slug ) {
	$source = file_get_contents( TAAS_CORE_PATH . 'pages/' . $slug . '.html' );
	if ( 'student-application' === $slug ) {
		taas_editor_check( has_blocks( $source ) && str_contains( $source, '<!-- wp:group' ) && str_contains( $source, '<!-- wp:taas/application-form' ) && ! str_contains( $source, '<!-- wp:html' ), 'Initial application uses editable blocks instead of raw HTML' );
		$page = get_post( TAAS_Pages::id( $slug ) );
		taas_editor_check( apply_filters( 'use_block_editor_for_post', true, $page ), 'Initial application uses the WordPress block editor' );
	} else {
		taas_editor_check( str_contains( $source, '<!-- wp:heading' ) && str_contains( $source, '<!-- wp:paragraph' ) && str_contains( $source, '<!-- wp:group' ), $slug . ' uses native editable content blocks' );
	}
	taas_editor_check( ! str_contains( $source, '[taas_student_application_form' ), $slug . ' does not use the legacy form shortcode' );
	$html = do_blocks( $source );
	taas_editor_check( 1 === substr_count( $html, 'id="student-application-form"' ) && ! str_contains( $html, '{{' ), $slug . ' renders one complete form' );
	$dom = new DOMDocument();
	@$dom->loadHTML( $html );
	$xpath = new DOMXPath( $dom );
	foreach ( array( 'ufid', 'ufEmail', 'countryOfOrigin', 'startSemester', 'collegeStatus', 'phone', 'eap5836Status', 'eap5837Status', 'taHours', 'englishScore', 'additionalInfo', 'resumeUrl' ) as $name ) {
		taas_editor_check( 1 === $xpath->query( '//form//*[@name="' . $name . '"]' )->length, $slug . ' retains ' . $name . ' inside the form' );
	}
}

$custom = render_block( array( 'blockName' => 'taas/application-field', 'attrs' => array( 'field' => 'phone', 'label' => 'Contact phone & extension <script>bad()</script>' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
taas_editor_check( str_contains( $custom, 'Contact phone &amp; extension' ) && str_contains( $custom, 'name="phone"' ) && ! str_contains( $custom, '<script>' ), 'Edited field labels render safely without changing the backend field name' );

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
	taas_editor_check( get_post_field( 'post_content', $ids[0] ) === file_get_contents( TAAS_CORE_PATH . 'pages/student-application.html' ), 'Explicit import can restore the selected source page' );
	taas_editor_check( str_contains( get_post_field( 'post_content', $ids[1] ), 'Editor preservation fixture' ) && str_contains( get_post_field( 'post_content', $ids[2] ), 'Editor preservation fixture' ), 'Explicit import leaves other student pages untouched' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	foreach ( $ids as $id ) { clean_post_cache( $id ); }
}
