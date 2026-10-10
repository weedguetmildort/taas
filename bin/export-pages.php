<?php
/**
 * Exports managed page content from the database back to pages/<slug>.html
 *
 * Run inside the container: php bin/export-pages.php
 *
 * @package TAAS_Core
 */

require '/var/www/html/wp-load.php';

$changed = TAAS_Pages::export();

echo $changed ? 'Updated: ' . implode( ', ', $changed ) . "\n" : "No changes.\n";
