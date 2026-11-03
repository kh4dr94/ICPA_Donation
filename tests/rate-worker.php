<?php
// Disposable security-test subprocess only; excluded from the plugin ZIP.
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
echo ICPA_Security::rate_limit( 'security-concurrent', 3, 60, $argv[1] ) ? '1' : '0';
