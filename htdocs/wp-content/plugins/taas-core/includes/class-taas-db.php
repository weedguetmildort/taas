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
	 * Create option key prefix for stored schema fingerprints
	 */
	private const FINGERPRINT_OPTION_PREFIX = 'taas_schema_fingerprint_';

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
	 * Hash every file the installer reads, so any schema change is detected
	 *
	 * Covers the contents of sql/schema.sql, sql/constraints.sql and every
	 * page file, plus the names of migrations. Names suffice for migrations,
	 * because the runner tracks them by filename and never re-runs one. Page
	 * contents count, because the installer syncs them into existing pages.
	 *
	 * @param string $plugin_path Plugin root directory
	 * @return string SHA-256 hex digest
	 */
	public static function fingerprint( string $plugin_path ): string {
		$plugin_path = rtrim( $plugin_path, '/\\' ) . '/';
		$parts       = array();

		foreach ( array( 'sql/schema.sql', 'sql/constraints.sql' ) as $file ) {
			$path    = $plugin_path . $file;
			$parts[] = $file . ':' . ( is_file( $path ) ? (string) hash_file( 'sha256', $path ) : 'missing' );
		}

		$migrations = glob( $plugin_path . 'migrations/*.php' );
		$names      = array_map( 'basename', false === $migrations ? array() : $migrations );
		sort( $names, SORT_STRING );
		$parts[] = 'migrations:' . implode( ',', $names );

		$pages = glob( $plugin_path . 'pages/*.html' );
		$pages = false === $pages ? array() : $pages;
		sort( $pages, SORT_STRING );

		foreach ( $pages as $path ) {
			$parts[] = 'pages/' . basename( $path ) . ':' . (string) hash_file( 'sha256', $path );
		}

		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/**
	 * Read the stored schema fingerprint for a component
	 *
	 * @param string $component Short component key
	 * @return string Stored fingerprint, or '' if never installed
	 */
	public static function get_fingerprint( string $component ): string {
		return (string) get_option( self::FINGERPRINT_OPTION_PREFIX . $component, '' );
	}

	/**
	 * Store the schema fingerprint for a component
	 *
	 * @param string $component Short component key
	 * @param string $fingerprint Value from fingerprint()
	 * @return void
	 */
	public static function set_fingerprint( string $component, string $fingerprint ): void {
		update_option( self::FINGERPRINT_OPTION_PREFIX . $component, $fingerprint, false );
	}

	/**
	 * Whether the installer needs to run
	 *
	 * True when the code version is newer than the stored one, or when the
	 * schema files differ from the last install. Deploys check out a tag with
	 * the build segment at 0, so the fingerprint is what catches a new
	 * migration or column; the version still catches non-file changes such as
	 * roles. Running when nothing changed is harmless, since every install
	 * step is idempotent.
	 *
	 * @param string $component Short component key
	 * @param string $version Version to compare against
	 * @param string $fingerprint Current value from fingerprint(), or '' to skip that check
	 * @return bool True if the installer should run
	 */
	public static function needs_upgrade( string $component, string $version, string $fingerprint = '' ): bool {
		if ( version_compare( self::get_schema_version( $component ), $version, '<' ) ) {
			return true;
		}

		return '' !== $fingerprint && self::get_fingerprint( $component ) !== $fingerprint;
	}

	/**
	 * Take the install lock without waiting
	 *
	 * Uses a MySQL named lock, so it holds across all web nodes sharing the
	 * database, and MySQL frees it when the request's connection closes, even
	 * after a fatal error. The name is hashed with the database name and table
	 * prefix because the MySQL server is shared with other sites.
	 *
	 * @param string $component Short component key
	 * @return bool True if this request now holds the lock
	 */
	public static function acquire_install_lock( string $component ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock must never be cached
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::lock_name( $component ) ) );
	}

	/**
	 * Release the install lock
	 *
	 * @param string $component Short component key
	 * @return void
	 */
	public static function release_install_lock( string $component ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock must never be cached
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::lock_name( $component ) ) );
	}

	/**
	 * Build a server-wide unique lock name, within MySQL's 64-character limit
	 *
	 * @param string $component Short component key
	 * @return string Lock name
	 */
	private static function lock_name( string $component ): string {
		global $wpdb;
		return 'taas_install_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . $component );
	}
}
