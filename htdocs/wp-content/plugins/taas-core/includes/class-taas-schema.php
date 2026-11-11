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
class TAAS_Schema {

	/**
	 * Hold the authored SQL, relative to the plugin root
	 */
	private const SQL_DIR = 'sql/';

	/**
	 * Hold a placeholder until replaced with the real table prefix at load time
	 */
	private const PLACEHOLDER = '{prefix}';

	/**
	 * Set up cached bare table names, in the order they appear in schema.sql
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

		$sql = self::read( 'schema.sql' );

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
	 * @param string $dir Directory holding schema.sql and constraints.sql; defaults to sql/
	 * @return void
	 */
	public static function install( string $dir = '' ): void {
		$statements = self::statements( 'schema.sql', $dir );

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

		self::apply_constraints( $dir );
	}

	/**
	 * Add the foreign keys and checks in sql/constraints.sql
	 *
	 * Constraints are matched by name only, so an existing one is never altered.
	 * Changing a definition needs a new name, see migrations/README.md.
	 *
	 * @param string $dir Directory holding constraints.sql; defaults to sql/
	 * @return int Number of constraints added
	 */
	public static function apply_constraints( string $dir = '' ): int {
		global $wpdb;

		$statements = self::statements( 'constraints.sql', $dir );
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
	 * List every way the live tables differ from sql/, or nothing when they match
	 *
	 * Asks dbDelta what it would still change, without changing anything, then
	 * checks what dbDelta cannot see: columns that sql/ no longer defines, and
	 * constraints that are missing or, for foreign keys, defined differently.
	 * MySQL rewrites CHECK expressions, so those are matched by name only.
	 *
	 * @param string $dir Directory holding schema.sql and constraints.sql; defaults to sql/
	 * @return string[] One line per difference
	 */
	public static function drift( string $dir = '' ): array {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$problems = array();
		$charset  = TAAS_DB::charset_collate();

		foreach ( self::statements( 'schema.sql', $dir ) as $sql ) {
			foreach ( dbDelta( $sql . ' ' . $charset, false ) as $change ) {
				$problems[] = 'Not applied: ' . $change;
			}

			if ( ! preg_match( '/CREATE\s+TABLE\s+`?(\w+)`?\s*\((.*)\)/is', $sql, $matches ) ) {
				continue;
			}

			$expected = self::column_names( $matches[2] );
			$live     = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $matches[1] ) );

			foreach ( array_diff( $live, $expected ) as $column ) {
				$problems[] = 'Column not in schema.sql: ' . $matches[1] . '.' . $column;
			}
		}

		foreach ( self::statements( 'constraints.sql', $dir ) as $sql ) {
			$name = self::constraint_name( $sql );

			if ( '' === $name ) {
				continue;
			}

			if ( ! self::constraint_exists( $name ) ) {
				$problems[] = 'Missing constraint: ' . $name;
				continue;
			}

			$expected = self::foreign_key_from_sql( $sql );

			if ( null === $expected ) {
				continue;
			}

			$live = self::foreign_key_from_database( $name );

			if ( $live !== $expected ) {
				$problems[] = sprintf(
					'Constraint %s is %s, but constraints.sql defines %s',
					$name,
					null === $live ? 'not a foreign key' : self::describe_foreign_key( $live ),
					self::describe_foreign_key( $expected )
				);
			}
		}

		return $problems;
	}

	/**
	 * Column names declared in the body of a CREATE TABLE statement
	 *
	 * @param string $body Text between the statement's outer parentheses
	 * @return string[]
	 */
	private static function column_names( string $body ): array {
		$columns = array();

		foreach ( preg_split( '/,\s*\n/', $body ) as $line ) {
			$line = trim( $line );

			if ( preg_match( '/^(PRIMARY|UNIQUE|KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN|CHECK)\b/i', $line ) ) {
				continue;
			}

			if ( preg_match( '/^`?(\w+)`?\s/', $line, $matches ) ) {
				$columns[] = $matches[1];
			}
		}

		return $columns;
	}

	/**
	 * The foreign key an ADD CONSTRAINT statement defines, in a comparable form
	 *
	 * @param string $sql Statement
	 * @return array{table: string, columns: string, references: string, delete: string}|null Null for a CHECK
	 */
	private static function foreign_key_from_sql( string $sql ): ?array {
		$pattern = '/ALTER\s+TABLE\s+`?(\w+)`?.*?FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)(?:\s+ON\s+DELETE\s+(CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))?/is';

		if ( ! preg_match( $pattern, $sql, $matches ) ) {
			return null;
		}

		return array(
			'table'      => $matches[1],
			'columns'    => self::column_list( $matches[2] ),
			'references' => $matches[3] . '(' . self::column_list( $matches[4] ) . ')',
			'delete'     => self::delete_rule( $matches[5] ?? '' ),
		);
	}

	/**
	 * The foreign key the database holds under a name, in the same form
	 *
	 * @param string $name Constraint name
	 * @return array{table: string, columns: string, references: string, delete: string}|null Null if it is not a foreign key
	 */
	private static function foreign_key_from_database( string $name ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE'
				. ' FROM information_schema.KEY_COLUMN_USAGE k'
				. ' INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
				. ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME'
				. ' WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.CONSTRAINT_NAME = %s'
				. ' ORDER BY k.ORDINAL_POSITION',
				$name
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return null;
		}

		return array(
			'table'      => $rows[0]['TABLE_NAME'],
			'columns'    => implode( ',', array_column( $rows, 'COLUMN_NAME' ) ),
			'references' => $rows[0]['REFERENCED_TABLE_NAME'] . '(' . implode( ',', array_column( $rows, 'REFERENCED_COLUMN_NAME' ) ) . ')',
			'delete'     => self::delete_rule( $rows[0]['DELETE_RULE'] ),
		);
	}

	/**
	 * Normalise a column list such as "`a`, b" to "a,b"
	 *
	 * @param string $columns Column list
	 * @return string
	 */
	private static function column_list( string $columns ): string {
		return implode( ',', array_map( static fn( string $c ): string => trim( $c, " \t\n\r`" ), explode( ',', $columns ) ) );
	}

	/**
	 * Normalise an ON DELETE rule; MySQL reports an omitted one as NO ACTION, which behaves as RESTRICT
	 *
	 * @param string $rule Rule as written or reported
	 * @return string
	 */
	private static function delete_rule( string $rule ): string {
		$rule = strtoupper( (string) preg_replace( '/\s+/', ' ', trim( $rule ) ) );

		return in_array( $rule, array( '', 'RESTRICT' ), true ) ? 'NO ACTION' : $rule;
	}

	/**
	 * Describe a foreign key for an error message
	 *
	 * @param array $fk Output of foreign_key_from_sql() or foreign_key_from_database()
	 * @return string
	 */
	private static function describe_foreign_key( array $fk ): string {
		return sprintf( '%s(%s) -> %s ON DELETE %s', $fk['table'], $fk['columns'], $fk['references'], $fk['delete'] );
	}

	/**
	 * Read a SQL file, strips comments, substitutes the prefix, and splits it into statements
	 *
	 * @param string $file File name inside $dir
	 * @param string $dir  Directory to read from; defaults to sql/
	 * @return string[] Statements, without trailing semicolons
	 */
	private static function statements( string $file, string $dir = '' ): array {
		$sql = self::read( $file, $dir );

		if ( '' === $sql ) {
			return array();
		}

		// Strip full-line and trailing comments
		$sql = (string) preg_replace( '/(^|\s)--(\s.*)?$/m', '', $sql );

		$sql = str_replace( self::PLACEHOLDER, self::prefix(), $sql );

		$parts = array_map( 'trim', explode( ';', $sql ) );

		return array_values( array_filter( $parts, static fn( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Read a file from sql/, or from another directory holding the same files
	 *
	 * @param string $file File name
	 * @param string $dir  Directory to read from; defaults to sql/
	 * @return string Contents, or '' if missing
	 */
	private static function read( string $file, string $dir = '' ): string {
		$path = ( '' === $dir ? TAAS_CORE_PATH . self::SQL_DIR : trailingslashit( $dir ) ) . $file;

		if ( ! file_exists( $path ) ) {
			TAAS_Logger::error( 'SQL file missing.', array( 'file' => $file ) );
			return '';
		}

		$contents = file_get_contents( $path );

		return false === $contents ? '' : $contents;
	}

	/**
	 * Extract the constraint name from an ALTER TABLE ... ADD CONSTRAINT
	 *
	 * @param string $sql Statement
	 * @return string Name, or '' if not found
	 */
	private static function constraint_name( string $sql ): string {
		if ( preg_match( '/ADD\s+CONSTRAINT\s+`?(\w+)`?/i', $sql, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Check whether a named constraint exists anywhere in this database
	 *
	 * @param string $name Constraint name
	 * @return bool
	 */
	private static function constraint_exists( string $name ): bool {
		global $wpdb;

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
	 * Whether a table exists
	 *
	 * @param string $bare Bare table name
	 * @return bool
	 */
	public static function exists( string $bare ): bool {
		global $wpdb;

		$table = self::table( $bare );

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Return row counts keyed by bare table name
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

			$counts[ $bare ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return $counts;
	}
}
