<?php
/**
 * Import seeds/demo-data.json
 *
 * @package TAAS_Core
 */

require '/var/www/html/wp-load.php';

$args = (array) ( $argv ?? array() );

if ( in_array( '--fresh', $args, true ) ) {
	TAAS_Seeds::truncate_all();
	echo "Cleared existing rows.\n";
}

$inserted = TAAS_Seeds::import( 'demo-data.json' );

if ( empty( $inserted ) ) {
	echo "Nothing inserted. Rows already present.\n";
	exit( 0 );
}

foreach ( $inserted as $table => $count ) {
	printf( "  %-22s +%d\n", $table, $count );
}