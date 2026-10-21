<?php
/**
 * PHPUnit bootstrap
 *
 * Plugin class files exit unless ABSPATH is defined, so define it before the
 * Composer autoloader can load them. Tests here cover pure logic only;
 * the few WordPress constants and functions that logic touches are stubbed.
 *
 * @package TAAS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/taas-wordpress-stub/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	// WordPress defines this; repositories pass it to $wpdb reads.
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stand-in for the WordPress escaper, which tests run without.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text );
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
