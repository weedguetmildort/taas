<?php
/**
 * Write a snapshot of each TAAS table to snapshots/taas-core/<table>.sql
 *
 * @package TAAS_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// Snapshots live at the repo root, outside the plugin and the document root
define( 'TAAS_SNAPSHOT_DIR', dirname( __DIR__ ) . '/snapshots/taas-core/' );

require '/var/www/html/wp-load.php';

$changed = TAAS_Snapshots::export();

echo $changed ? 'Updated: ' . implode( ', ', $changed ) . "\n" : "No changes.\n";
