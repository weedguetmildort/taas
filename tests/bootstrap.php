<?php
/**
 * PHPUnit bootstrap
 *
 * Plugin class files exit unless ABSPATH is defined, so define it before the
 * Composer autoloader can load them. Tests here cover pure logic only;
 * nothing calls into WordPress.
 *
 * @package TAAS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/taas-wordpress-stub/' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
