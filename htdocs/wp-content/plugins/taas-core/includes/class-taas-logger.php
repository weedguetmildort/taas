<?php
/**
 * Logging helper for the TAAS plugins
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Implement a thin wrapper over error_log()
 */
class TAAS_Logger {

	public const LEVEL_DEBUG = 'DEBUG';
	public const LEVEL_INFO  = 'INFO';
	public const LEVEL_WARN  = 'WARN';
	public const LEVEL_ERROR = 'ERROR';

	/**
	 * Write a line to the WordPress debug log
	 *
	 * @param string $message Message to log
	 * @param string $level   One of the LEVEL_* constants
	 * @param array  $context Optional extra data, JSON-encoded onto the line
	 * @return void
	 */
	public static function log( string $message, string $level = self::LEVEL_INFO, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$line = sprintf( '[TAAS][%s] %s', $level, $message );

		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );
			if ( false !== $encoded ) {
				$line .= ' ' . $encoded;
			}
		}
		error_log( $line );
	}

	/**
	 * Log at DEBUG level
	 *
	 * @param string $message Message to log
	 * @param array  $context Optional extra data
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( $message, self::LEVEL_DEBUG, $context );
	}

	/**
	 * Log at INFO level
	 *
	 * @param string $message Message to log
	 * @param array  $context Optional extra data
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( $message, self::LEVEL_INFO, $context );
	}

	/**
	 * Log at WARN level
	 *
	 * @param string $message Message to log
	 * @param array  $context Optional extra data
	 * @return void
	 */
	public static function warn( string $message, array $context = array() ): void {
		self::log( $message, self::LEVEL_WARN, $context );
	}

	/**
	 * Log at ERROR level
	 *
	 * @param string $message Message to log
	 * @param array  $context Optional extra data
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( $message, self::LEVEL_ERROR, $context );
	}
}
