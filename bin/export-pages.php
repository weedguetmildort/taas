<?php
// bin/export-pages.php to run inside the container
require '/var/www/html/wp-load.php';
$changed = TAAS_Pages::export();
echo $changed ? "Updated: " . implode( ', ', $changed ) . "\n" : "No changes.\n";