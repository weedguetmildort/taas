<?php
/**
 * Import courses and professors from a CSV or XLSX file
 *
 * Run inside the container: php bin/import-courses.php <path-to-file>
 *
 * @package TAAS_Core
 */

require '/var/www/html/wp-load.php';

$file = $argv[1] ?? '';

if ( '' === $file ) {
	fwrite( STDERR, "Usage: php bin/import-courses.php <path-to-csv-or-xlsx>\n" );
	exit( 1 );
}

try {
	// Ask user for course term
    $course_term = trim( readline( 'Enter course term (e.g. Fall 2026): ' ) );

    if ( '' === $course_term ) {
        throw new RuntimeException( 'Course term cannot be empty.' );
    }

	$counts = taas_import_courses( $file, $course_term );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Import failed, nothing saved: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

echo "Import completed successfully!\n";
echo "New professors added: {$counts['professors']}\n";
echo "Courses added: {$counts['courses']}\n";
