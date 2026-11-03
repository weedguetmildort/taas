<?php
/** One-time conversion. Run BEFORE removing the legacy custom-block registration.
 * Preview: docker compose exec -T wordpress php /var/www/taas/scripts/convert-student-components.php
 * Apply: append --apply /var/www/taas/real-data/student-components-before.json
 * Converts source pages and saved pages independently, preserving editor changes.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }
$_GET['taas_role'] = 'student';

function student_static_home_link( array $block ): string {
	$type = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );
	$a = $type->prepare_attributes_for_render( $block['attrs'] );
	$html = render_block( $block );
	$dom = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	$anchor = $dom->getElementsByTagName( 'a' )->item( 0 );
	if ( ! $anchor ) { throw new RuntimeException( 'Could not convert homepage link.' ); }
	$anchor->removeAttribute( 'href' );
	$anchor->removeAttribute( 'aria-describedby' );
	$anchor->setAttribute( 'data-student-destination', $a['destination'] );
	$wrapper = $anchor->parentNode;
	foreach ( iterator_to_array( $wrapper->getElementsByTagName( 'p' ) ) as $message ) { $message->remove(); }
	if ( 'student-application' === $a['destination'] ) {
		foreach ( array( 'applied' => $a['alreadyAppliedMessage'], 'unavailable' => $a['unavailableMessage'] ) as $key => $text ) {
			$node = $dom->createElement( 'p' );
			$node->textContent = $text;
			$node->setAttribute( 'class', 'student-home-application-note' );
			$node->setAttribute( 'data-home-' . $key, '' );
			$node->setAttribute( 'hidden', '' );
			$wrapper->appendChild( $node );
		}
	}
	return $dom->saveHTML( $wrapper );
}

function student_static_components( array $blocks ): array {
	$result = array();
	foreach ( $blocks as $block ) {
		if ( str_starts_with( $block['blockName'] ?? '', 'taas/' ) ) {
			if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $block['blockName'] ) ) {
				throw new RuntimeException( 'Run this conversion with the previous custom-block plugin installed: ' . $block['blockName'] );
			}
			// Render a container's children together, avoiding unmatched form tags across blocks.
			$html = 'taas/homepage-link' === $block['blockName'] ? student_static_home_link( $block ) : render_block( $block );
			if ( '' === trim( $html ) ) { throw new RuntimeException( 'Empty component: ' . $block['blockName'] ); }
			$result[] = array( 'blockName' => 'core/html', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) );
		} else {
			$block['innerBlocks'] = student_static_components( $block['innerBlocks'] );
			$result[] = $block;
		}
	}
	return $result;
}

$changes = array( 'sources' => array(), 'pages' => array() );
foreach ( array( 'student-application', 'check-application-status', 'front-page' ) as $slug ) {
	$path = TAAS_CORE_PATH . 'pages/' . $slug . '.html';
	$before = file_get_contents( $path );
	if ( str_contains( $before, '<!-- wp:taas/' ) ) {
		$changes['sources'][ $path ] = array( 'before' => $before, 'after' => serialize_blocks( student_static_components( parse_blocks( $before ) ) ) );
	}
	$id = TAAS_Pages::id( $slug );
	if ( ! $id ) { continue; }
	$before = get_post_field( 'post_content', $id );
	if ( str_contains( $before, '<!-- wp:taas/' ) ) {
		$changes['pages'][ $id ] = array( 'before' => $before, 'after' => serialize_blocks( student_static_components( parse_blocks( $before ) ) ) );
	}
}
echo count( $changes['sources'] ) . ' source pages, ' . count( $changes['pages'] ) . ' saved pages to convert.' . PHP_EOL;
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
			throw new RuntimeException( 'Source changed or cannot be written: ' . $path );
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
echo 'Converted; backup: ' . $backup . PHP_EOL;
