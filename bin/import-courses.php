<?php
/**
 * Import courses from an XLSX or CSV file
 *
 * Run inside the container:
 *   php /var/www/taas/bin/import-courses.php <courses.xlsx|courses.csv>
 *
 * Each course's professor is found by email, and added if new.
 *
 * @package TAAS_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$courses_file = $argv[1] ?? '';

if ( '' === $courses_file ) {
	fwrite( STDERR, "Usage: php bin/import-courses.php <courses.xlsx|courses.csv>\n" );
	exit( 1 );
}

// PhpSpreadsheet lives in the repo-root vendor, not in the plugin. Only XLSX needs it.
if ( 'csv' !== strtolower( pathinfo( $courses_file, PATHINFO_EXTENSION ) ) ) {
	require dirname( __DIR__ ) . '/vendor/autoload.php';
}
require '/var/www/html/wp-load.php';

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
