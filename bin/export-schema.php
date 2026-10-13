<?php
/**
 * Write a snapshot of each TAAS table to snapshots/<table>.sql
 *
 * @package TAAS_Core
 */

require '/var/www/html/wp-load.php';

$changed = TAAS_Snapshots::export();

echo $changed ? 'Updated: ' . implode( ', ', $changed ) . "\n" : "No changes.\n";
