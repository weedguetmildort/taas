<?php
/**
 * Prints the state of the TAAS database: table presence, row counts,
 * constraints, and applied or pending migrations
 *
 * @package TAAS_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require '/var/www/html/wp-load.php';

global $wpdb;

$dir = TAAS_CORE_PATH . 'migrations/';

echo "Tables\n";

foreach ( TAAS_Schema::counts() as $bare => $count ) {
	$label = -1 === $count ? 'MISSING' : $count . ' rows';
	printf( "  %-22s %s\n", $bare, $label );
}

echo "\nConstraints\n";

$constraints = $wpdb->get_results(
	"SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
	 FROM information_schema.TABLE_CONSTRAINTS
	 WHERE CONSTRAINT_SCHEMA = DATABASE()
	 AND ( CONSTRAINT_NAME LIKE 'fk\_%' OR CONSTRAINT_NAME LIKE 'chk\_%' )
	 ORDER BY CONSTRAINT_NAME",
	ARRAY_A
);

if ( empty( $constraints ) ) {
	echo "  none found\n";
} else {
	foreach ( $constraints as $row ) {
		printf( "  %-28s %s\n", $row['CONSTRAINT_NAME'], $row['CONSTRAINT_TYPE'] );
	}
}

echo "\nMigrations\n";

$applied = TAAS_Migrations::applied( 'core' );
$pending = TAAS_Migrations::pending( 'core', $dir );

echo '  applied: ' . ( $applied ? implode( ', ', $applied ) : 'none' ) . "\n";
echo '  pending: ' . ( $pending ? implode( ', ', $pending ) : 'none' ) . "\n";
echo '  schema version: ' . TAAS_DB::get_schema_version( 'core' ) . "\n";

$stored  = TAAS_DB::get_fingerprint( 'core' );
$current = TAAS_DB::fingerprint( TAAS_CORE_PATH );

echo "\nFingerprint\n";
echo '  stored:  ' . ( '' === $stored ? 'none' : $stored ) . "\n";
echo '  current: ' . $current . "\n";
echo '  upgrade: ' . ( TAAS_DB::needs_upgrade( 'core', TAAS_CORE_VERSION, $current ) ? 'pending, runs on next wp-admin load' : 'up to date' ) . "\n";
