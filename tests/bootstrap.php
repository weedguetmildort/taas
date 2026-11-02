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

if ( ! function_exists( '__' ) ) {
	/**
	 * Stand-in for the WordPress translator; returns the text unchanged.
	 *
	 * @param string $text Text to translate.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stand-in for the WordPress key sanitizer.
	 *
	 * @param string $key Key to sanitize.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return strtolower( (string) $key );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stand-in for the WordPress unslasher; tests pass unslashed values.
	 *
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
