<?php
/**
 * Import courses and professors from a CSV or XLSX file
 *
 * Run inside the container: php /var/www/taas/bin/import-courses.php <path-to-file>
 *
 * @package TAAS_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// PhpSpreadsheet lives in the repo-root vendor, not in the plugin
require dirname( __DIR__ ) . '/vendor/autoload.php';
require '/var/www/html/wp-load.php';

$file = $argv[1] ?? '';

if ( '' === $file ) {
	fwrite( STDERR, "Usage: php bin/import-courses.php <path-to-csv-or-xlsx>\n" );
	exit( 1 );
}

try {
	// Ask user for the term the courses belong to
	$semester_input = trim( (string) readline( 'Enter semester (Fall, Spring, Summer A, Summer B, Summer C): ' ) );
	$semester       = TAAS_Semester::try_from_label( $semester_input );

	if ( null === $semester ) {
		throw new RuntimeException( 'Unknown semester: "' . $semester_input . '".' );
	}

	$year_input = trim( (string) readline( 'Enter year (e.g. 2026): ' ) );

	if ( 1 !== preg_match( '/^\d{4}$/', $year_input ) ) {
		throw new RuntimeException( 'Year must be four digits, got "' . $year_input . '".' );
	}

	$counts = taas_import_courses( $file, $semester, (int) $year_input );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Import failed, nothing saved: ' . $e->getMessage() . "\n" );
	exit( 1 );
}

echo "Import completed successfully!\n";
echo "New professors added: {$counts['professors']}\n";
echo "Courses added: {$counts['courses']}\n";
