<?php
/**
 * Load and apply the project's schema from sql/
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply the schema defined in sql/
 */
class TAAS_Tables {

	/**
	 * Hold the authored SQL, relative to the plugin root
	 */
	private const SNAPSHOT_DIR = 'snapshots/';

	/**
	 * Placeholder replaced with the real table prefix at load time
	 */
	private const PLACEHOLDER = '{prefix}';

	/**
	 * Cached bare table names, in the order they appear in schema.sql
	 *
	 * @var string[]|null
	 */
	private static ?array $names = null;

	/**
	 * Return the prefix every TAAS core table shares
	 *
	 * @return string e.g. wp_taas_core_
	 */
	public static function prefix(): string {
		global $wpdb;

		return $wpdb->prefix . TAAS_DB::NS_CORE;
	}

	/**
	 * Return the fully qualified name for one table
	 *
	 * @param string $bare Bare table name, e.g. 'applications'
	 * @return string
	 */
	public static function table( string $bare ): string {
		return self::prefix() . $bare;
	}

	/**
	 * Return bare table names in dependency order
	 *
	 * @return string[]
	 */
	public static function names(): array {
		if ( null !== self::$names ) {
			return self::$names;
		}

		$sql = self::read( 'tables.sql' );

		preg_match_all(
			'/CREATE\s+TABLE\s+' . preg_quote( self::PLACEHOLDER, '/' ) . '(\w+)/i',
			$sql,
			$matches
		);

		self::$names = $matches[1] ?? array();

		return self::$names;
	}

	/**
	 * Return every fully qualified table name
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_map( array( self::class, 'table' ), self::names() );
	}

	/**
	 * Create or update every table, then adds any missing constraints
	 *
	 * @return void
	 */
	public static function install(): void {
		$statements = self::statements( 'schema.sql' );

		if ( empty( $statements ) ) {
			TAAS_Logger::error( 'No table definitions found in sql/schema.sql.' );
			return;
		}

		$charset = TAAS_DB::charset_collate();

		TAAS_DB::delta(
			array_map(
				static function ( string $sql ) use ( $charset ): string {
					return $sql . ' ' . $charset;
				},
				$statements
			)
		);

		TAAS_Logger::info( 'Tables applied.', array( 'count' => count( $statements ) ) );

		self::apply_constraints();
	}

	/**
	 * Add the foreign keys and checks in sql/constraints.sql
	 *
	 * @return int Number of constraints added.
	 */
	public static function apply_constraints(): int {
		global $wpdb;

		$statements = self::statements( 'constraints.sql' );
		$added      = 0;

		foreach ( $statements as $sql ) {
			$name = self::constraint_name( $sql );

			if ( '' === $name ) {
				TAAS_Logger::warn( 'Could not read constraint name; skipping.', array( 'sql' => substr( $sql, 0, 80 ) ) );
				continue;
			}

			if ( self::constraint_exists( $name ) ) {
				continue;
			}

			$wpdb->query( $sql );

			if ( '' !== $wpdb->last_error ) {
				TAAS_Logger::error(
					'Constraint failed.',
					array(
						'constraint' => $name,
						'db_error'   => $wpdb->last_error,
					)
				);
				continue;
			}

			++$added;
			TAAS_Logger::info( 'Constraint added.', array( 'constraint' => $name ) );
		}

		return $added;
	}

	/**
	 * Reads a SQL file, strips comments, substitutes the prefix, and splits
	 * it into statements.
	 *
	 * Splitting on semicolons is naive: it would break if a default value or
	 * a string literal contained one. Fine for schema definitions, worth
	 * knowing before adding anything exotic.
	 *
	 * @param string $file File name inside sql/.
	 * @return string[] Statements, without trailing semicolons.
	 */
	private static function statements( string $file ): array {
		$sql = self::read( $file );

		if ( '' === $sql ) {
			return array();
		}

		// Strip line comments.
		$sql = (string) preg_replace( '/^\s*--.*$/m', '', $sql );

		$sql = str_replace( self::PLACEHOLDER, self::prefix(), $sql );

		$parts = array_map( 'trim', explode( ';', $sql ) );

		return array_values( array_filter( $parts, static fn( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Reads a file from sql/.
	 *
	 * @param string $file File name.
	 * @return string Contents, or '' if missing.
	 */
	private static function read( string $file ): string {
		$path = TAAS_CORE_PATH . self::SQL_DIR . $file;

		if ( ! file_exists( $path ) ) {
			TAAS_Logger::error( 'SQL file missing.', array( 'file' => $file ) );
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );

		return false === $contents ? '' : $contents;
	}

	/**
	 * Extracts the constraint name from an ALTER TABLE ... ADD CONSTRAINT.
	 *
	 * @param string $sql Statement.
	 * @return string Name, or '' if not found.
	 */
	private static function constraint_name( string $sql ): string {
		if ( preg_match( '/ADD\s+CONSTRAINT\s+`?(\w+)`?/i', $sql, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Whether a named constraint exists anywhere in this database.
	 *
	 * Constraint names are unique per schema in MySQL, so the table does not
	 * need checking too.
	 *
	 * @param string $name Constraint name.
	 * @return bool
	 */
	private static function constraint_exists( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
				 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s',
				$name
			)
		);

		return null !== $found;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $bare Bare table name.
	 * @return bool
	 */
	public static function exists( string $bare ): bool {
		global $wpdb;

		$table = self::table( $bare );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Returns row counts keyed by bare table name.
	 *
	 * Missing tables report -1 so they are distinguishable from empty ones.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;

		$counts = array();

		foreach ( self::names() as $bare ) {
			if ( ! self::exists( $bare ) ) {
				$counts[ $bare ] = -1;
				continue;
			}

			$table = self::table( $bare );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts[ $bare ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return $counts;
	}
}
