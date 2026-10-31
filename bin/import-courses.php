<?php
/**
 * Import professors, then courses, from XLSX files
 *
 * Run inside the container:
 *   php /var/www/taas/bin/import-courses.php <courses.xlsx> --professors=<professors.xlsx>
 *
 * Professors are imported first, so the courses can find them.
 *
 * @package TAAS_Core
 */

use PhpOffice\PhpSpreadsheet\IOFactory;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// PhpSpreadsheet lives in the repo-root vendor, not in the plugin
require dirname( __DIR__ ) . '/vendor/autoload.php';
require '/var/www/html/wp-load.php';

/**
 * Insert every professor in an XLSX file with headers UFID, Name and GLID, in any order
 *
 * Assumes every row is valid.
 *
 * @param string $file_path Path to a .xlsx file
 * @return int Number of professors inserted
 */
function taas_import_professors( string $file_path ): int {
	global $wpdb;

	$rows = IOFactory::load( $file_path )->getSheet( 0 )->toArray( null, true, false );

	// Find which column each header is in
	$columns = array_flip( array_shift( $rows ) );
	$count   = 0;

	foreach ( $rows as $row ) {
		$wpdb->insert(
			TAAS_Schema::table( 'professors' ),
			array(
				'professor_ufid'  => (string) $row[ $columns['UFID'] ],
				'professor_name'  => (string) $row[ $columns['Name'] ],
				'professor_email' => (string) $row[ $columns['GLID'] ],
			)
		);

		++$count;
	}

	return $count;
}

$courses_file    = $argv[1] ?? '';
$professors_file = '';

if ( str_starts_with( $argv[2] ?? '', '--professors=' ) ) {
	$professors_file = substr( $argv[2], strlen( '--professors=' ) );
}

if ( '' === $courses_file || '' === $professors_file ) {
	fwrite( STDERR, "Usage: php bin/import-courses.php <courses.xlsx> --professors=<professors.xlsx>\n" );
	exit( 1 );
}

try {
	echo 'Professors added: ' . taas_import_professors( $professors_file ) . "\n";

	$result = taas_import_courses( $courses_file );

	echo "Courses: {$result['courses']} added, {$result['replaced']} replaced, {$result['skipped']} skipped\n";

	foreach ( $result['messages'] as $message ) {
		echo "  Row {$message['row']}, {$message['header']}: {$message['message']}\n";
	}
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Import failed: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
