<?php
/**
 * Database helpers shared by the TAAS plugins
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create table naming, schema versioning, and a dbDelta wrapper
 */
class TAAS_DB {

	/**
	 * Create namespace for tables owned by taas-core
	 */
	public const NS_CORE = 'taas_core_';

	/**
	 * Create namespace for tables owned by taas-onboarding
	 */
	public const NS_ONBOARDING = 'taas_onb_';

	/**
	 * Create namespace for tables owned by taas-matching
	 */
	public const NS_MATCHING = 'taas_match_';

	/**
	 * Create option key prefix for stored schema versions
	 */
	private const SCHEMA_OPTION_PREFIX = 'taas_schema_version_';

	/**
	 * Build a fully prefixed table name
	 *
	 * @param string $name One of the NS_* constants
	 * @param string $table Bare table name, e.g. 'applications'
	 * @return string Fully qualified table name, e.g. 'wp_taas_onb_applications'
	 */
	public static function table( string $name, string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $name . $table;
	}

	/**
	 * Create convenience wrapper for onboarding-owned tables
	 *
	 * @param string $table Bare table name
	 * @return string Fully qualified table name
	 */
	public static function onboarding_table( string $table ): string {
		return self::table( self::NS_ONBOARDING, $table );
	}

	/**
	 * Create convenience wrapper for matching-owned tables
	 *
	 * @param string $table Bare table name
	 * @return string Fully qualified table name
	 */
	public static function matching_table( string $table ): string {
		return self::table( self::NS_MATCHING, $table );
	}

	/**
	 * Return the charset/collate clause for CREATE TABLE statements
	 *
	 * @return string
	 */
	public static function charset_collate(): string {
		global $wpdb;
		return $wpdb->get_charset_collate();
	}

	/**
	 * Run one or more CREATE TABLE statements through dbDelta()
	 *
	 * @param string|string[] $sql One or more CREATE TABLE statements
	 * @return array Result from dbDelta, keyed by table/column
	 */
	public static function delta( $sql ): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$statements = is_array( $sql ) ? $sql : array( $sql );
		$results = array();

		foreach ( $statements as $statement ) {
			$results[] = dbDelta( $statement );
		}

		TAAS_Logger::debug( 'dbDelta executed.', array( 'statements' => count( $statements ) ) );

		return $results;
	}

	/**
	 * Read the stored schema version for a component
	 *
	 * @param string $component Short component key, e.g. 'core', 'onboarding'
	 * @return string Stored version, or '0.0.0' if never installed
	 */
	public static function get_schema_version( string $component ): string {
		return (string) get_option( self::SCHEMA_OPTION_PREFIX . $component, '0.0.0' );
	}

	/**
	 * Stores the schema version for a component
	 *
	 * @param string $component Short component key
	 * @param string $version Version string
	 * @return void
	 */
	public static function set_schema_version( string $component, string $version ): void {
		update_option( self::SCHEMA_OPTION_PREFIX . $component, $version, false );
	}

	/**
	 * Whether a stored schema is older than the given version
	 *
	 * @param string $component Short component key
	 * @param string $version Version to compare against
	 * @return bool True if a migration is needed
	 */
	public static function needs_upgrade( string $component, string $version ): bool {
		return version_compare( self::get_schema_version( $component ), $version, '<' );
	}
}
