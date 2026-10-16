<?php
/**
 * Exports managed page content from the database back to pages/<slug>.html
 *
 * Run inside the container: php /var/www/taas/bin/export-pages.php
 *
 * @package TAAS_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require '/var/www/html/wp-load.php';

$changed = TAAS_Pages::export();

echo $changed ? 'Updated: ' . implode( ', ', $changed ) . "\n" : "No changes.\n";
