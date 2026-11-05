<?php
/**
 * Import courses from an XLSX file
 *
 * Run inside the container:
 *   php /var/www/taas/bin/import-courses.php <courses.xlsx>
 *
 * Run against an empty professors table: professors are created from the file, not looked up.
 * Each professor is added the first time their email appears, with the database assigning their id,
 * and later rows with the same email reuse that id.
 *
 * @package TAAS_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// PhpSpreadsheet lives in the repo-root vendor, not in the plugin
require dirname( __DIR__ ) . '/vendor/autoload.php';
require '/var/www/html/wp-load.php';

// taas-core does not load the importer, so load it here. Use the plugin path, the same one
// the local taas-backend-test plugin uses, so require_once skips it if that plugin is active.
require_once WP_PLUGIN_DIR . '/taas-core/includes/func-taas-xlsx-ingest.php';

$courses_file = $argv[1] ?? '';

if ( '' === $courses_file ) {
	fwrite( STDERR, "Usage: php bin/import-courses.php <courses.xlsx>\n" );
	exit( 1 );
}

try {
	$result = taas_import_courses( $courses_file );

	echo "Courses: {$result['courses']} added, {$result['replaced']} replaced, {$result['skipped']} skipped\n";

	foreach ( $result['messages'] as $message ) {
		echo "  Row {$message['row']}, {$message['header']}: {$message['message']}\n";
	}
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Import failed: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
