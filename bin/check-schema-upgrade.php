<?php
/**
 * Check that upgrading from every release reaches the same schema as a fresh install
 *
 * Run through bin/check-schema-upgrade.sh, which extracts each release's SQL
 * from its git tag first. Uses two scratch databases, dropped afterwards.
 *
 *   php bin/check-schema-upgrade.php <releases-dir> [--write-snapshots]
 *
 * Needs TAAS_DB_ROOT_PASSWORD, since only root can create the scratch databases.
 *
 * @package TAAS_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// CI has no installed site; plugins are then not loaded, so load TAAS Core directly
define( 'WP_INSTALLING', true );
if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = 'localhost';
}
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

if ( ! class_exists( 'TAAS_Schema' ) ) {
	require_once WP_PLUGIN_DIR . '/taas-core/taas-core.php';
}

const TAAS_CHECK_UPGRADE_DB = 'taas_schema_check_upgrade';
const TAAS_CHECK_FRESH_DB   = 'taas_schema_check_fresh';

$releases_dir      = $argv[1] ?? '';
$write_snapshots   = in_array( '--write-snapshots', $argv, true );
$root_password     = (string) getenv( 'TAAS_DB_ROOT_PASSWORD' );
$snapshot_dir      = dirname( __DIR__ ) . '/snapshots/taas-core/';
$taas_check_prefix = $wpdb->base_prefix;

if ( '' === $releases_dir || ! is_dir( $releases_dir ) || '' === $root_password ) {
	fwrite( STDERR, "Run bin/check-schema-upgrade.sh, which extracts the releases and passes the root password.\n" );
	exit( 1 );
}

$admin = new wpdb( 'root', $root_password, DB_NAME, DB_HOST );
$admin->suppress_errors( true );

/**
 * Empty a scratch database and make it the one TAAS code uses
 *
 * @param string $name Scratch database name
 * @return wpdb
 */
function taas_check_use_database( string $name ): wpdb {
	global $admin, $root_password, $taas_check_prefix;

	$admin->query( $admin->prepare( 'DROP DATABASE IF EXISTS %i', $name ) );
	$admin->query( $admin->prepare( 'CREATE DATABASE %i', $name ) );

	$db = new wpdb( 'root', $root_password, $name, DB_HOST );
	$db->set_prefix( $taas_check_prefix );
	$db->suppress_errors( true );
	$db->show_errors( false );

	$GLOBALS['wpdb'] = $db;

	return $db;
}

/**
 * Run the migrations in a directory that have not run yet, as the runner would
 *
 * @param string   $dir Migrations directory
 * @param string[] $ran Ids already run, updated in place
 * @param string   $stage Label for errors
 * @return void
 * @throws RuntimeException If a migration fails.
 */
function taas_check_run_migrations( string $dir, array &$ran, string $stage ): void {
	global $wpdb;

	$files = glob( rtrim( $dir, '/' ) . '/*.php' );
	$files = false === $files ? array() : $files;
	sort( $files );

	foreach ( $files as $file ) {
		$id = basename( $file, '.php' );

		if ( in_array( $id, $ran, true ) ) {
			continue;
		}

		try {
			( require $file )( $wpdb );
		} catch ( Throwable $e ) {
			throw new RuntimeException( "Migration {$id} failed during {$stage}: " . $e->getMessage() );
		}

		$ran[] = $id;
	}
}

/**
 * Add one linked row per table, so later releases upgrade a database with data
 *
 * Values are derived from the columns, since this runs against the first
 * release's tables, which differ from the current ones.
 *
 * @return void
 * @throws RuntimeException If a row cannot be inserted.
 */
function taas_check_seed(): void {
	global $wpdb;

	$ids = array();

	foreach ( array( 'professors', 'students', 'student_profiles', 'courses', 'applications' ) as $bare ) {
		if ( ! TAAS_Schema::exists( $bare ) ) {
			continue;
		}

		$row = array();

		foreach ( $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', TAAS_Schema::table( $bare ) ), ARRAY_A ) as $column ) {
			$field = $column['Field'];
			$type  = strtolower( $column['Type'] );

			if ( str_contains( $column['Extra'], 'auto_increment' ) || ( 'YES' === $column['Null'] && ! str_contains( $field, 'ufid' ) ) ) {
				continue;
			}

			$row[ $field ] = match ( true ) {
				'professor_ufid' === $field    => '10000001',
				'student_ufid' === $field      => '20000001',
				'applied_course_id' === $field => $ids['courses'] ?? 1,
				str_contains( $field, 'email' ) => $bare . '@ufl.edu',
				str_starts_with( $type, 'varchar' ), str_starts_with( $type, 'text' ) => 'x',
				str_starts_with( $type, 'json' ) => '{}',
				str_starts_with( $type, 'date' ) => '2026-01-01',
				str_starts_with( $type, 'year' ) => 2026,
				str_starts_with( $type, 'decimal' ) => 3.5,
				default => $column['Default'] ?? 1,
			};
		}

		if ( false === $wpdb->insert( TAAS_Schema::table( $bare ), $row ) ) {
			throw new RuntimeException( "Seeding {$bare} failed: {$wpdb->last_error}" );
		}

		$ids[ $bare ] = $wpdb->insert_id;
	}
}

/**
 * Every TAAS core table's normalised CREATE TABLE, keyed by bare name
 *
 * @return array<string, string>
 */
function taas_check_tables(): array {
	global $wpdb;

	$tables = array();

	foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TAAS_Schema::prefix() ) . '%' ) ) as $table ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );

		$tables[ substr( $table, strlen( TAAS_Schema::prefix() ) ) ] = TAAS_Snapshots::normalise( (string) $row[1] );
	}

	ksort( $tables );

	return $tables;
}

/**
 * Definition lines of a CREATE TABLE, sorted, so column order is ignored
 *
 * @param string $ddl Normalised CREATE TABLE
 * @return string[]
 */
function taas_check_lines( string $ddl ): array {
	$lines = array_map( static fn( string $line ): string => rtrim( trim( $line ), ',' ), explode( "\n", trim( $ddl ) ) );
	sort( $lines );

	return $lines;
}

$failures = array();
$report   = static function ( string $check, array $problems ) use ( &$failures ): void {
	echo ( empty( $problems ) ? 'PASS' : 'FAIL' ) . ": {$check}\n";

	foreach ( $problems as $problem ) {
		echo "  {$problem}\n";
	}

	if ( ! empty( $problems ) ) {
		$failures[] = $check;
	}
};

try {
	// Fresh install of this branch, applied the way taas_core_install() applies it
	taas_check_use_database( TAAS_CHECK_FRESH_DB );
	$ran = array();
	TAAS_Schema::install();
	taas_check_run_migrations( TAAS_CORE_PATH . 'migrations', $ran, 'the fresh install' );
	TAAS_Schema::install();
	$report( 'Fresh install matches sql/', TAAS_Schema::drift() );
	$fresh = taas_check_tables();

	// Every release in order, as each deploy applied it, then this branch
	taas_check_use_database( TAAS_CHECK_UPGRADE_DB );
	$releases = glob( rtrim( $releases_dir, '/' ) . '/*', GLOB_ONLYDIR );
	$releases = false === $releases ? array() : $releases;
	sort( $releases );
	$ran = array();

	foreach ( $releases as $i => $release ) {
		TAAS_Schema::install( $release );
		taas_check_run_migrations( $release . '/migrations', $ran, 'release ' . basename( $release ) );

		if ( 0 === $i ) {
			taas_check_seed();
		}
	}

	TAAS_Schema::install();
	taas_check_run_migrations( TAAS_CORE_PATH . 'migrations', $ran, 'the upgrade to this branch' );
	TAAS_Schema::install();

	$path = empty( $releases ) ? 'no releases found' : basename( $releases[0] ) . ' -> ' . basename( end( $releases ) ) . ' -> this branch';
	echo "Upgrade path: {$path}\n";
	$report( 'Upgraded database matches sql/', TAAS_Schema::drift() );
	$upgraded = taas_check_tables();

	$differences = array();

	foreach ( array_unique( array_merge( array_keys( $fresh ), array_keys( $upgraded ) ) ) as $table ) {
		if ( ! isset( $upgraded[ $table ] ) ) {
			$differences[] = "{$table}: missing after upgrade";
			continue;
		}

		if ( ! isset( $fresh[ $table ] ) ) {
			$differences[] = "{$table}: left over after upgrade; drop it in a migration";
			continue;
		}

		$expected = taas_check_lines( $fresh[ $table ] );
		$actual   = taas_check_lines( $upgraded[ $table ] );

		foreach ( array_diff( $expected, $actual ) as $line ) {
			$differences[] = "{$table}: fresh install has    {$line}";
		}

		foreach ( array_diff( $actual, $expected ) as $line ) {
			$differences[] = "{$table}: upgraded database has {$line}";
		}
	}

	if ( ! empty( $differences ) ) {
		$differences[] = 'Changes dbDelta cannot make need a migration. See migrations/README.md.';
	}

	$report( 'Upgraded tables are identical to a fresh install', $differences );

	// Snapshots show reviewers the resulting tables, so they must be current
	$stale = array();

	foreach ( $fresh as $table => $ddl ) {
		$file = $snapshot_dir . $table . '.sql';

		// Windows checkouts may hold the snapshot with CRLF line endings
		if ( is_file( $file ) && str_replace( "\r\n", "\n", (string) file_get_contents( $file ) ) === $ddl ) {
			continue;
		}

		if ( $write_snapshots ) {
			file_put_contents( $file, $ddl );
			echo "  wrote snapshots/taas-core/{$table}.sql\n";
			continue;
		}

		$stale[] = "snapshots/taas-core/{$table}.sql " . ( is_file( $file ) ? 'is out of date' : 'is missing' );
	}

	$snapshot_files = glob( $snapshot_dir . '*.sql' );

	foreach ( false === $snapshot_files ? array() : $snapshot_files as $file ) {
		if ( ! isset( $fresh[ basename( $file, '.sql' ) ] ) ) {
			$stale[] = 'snapshots/taas-core/' . basename( $file ) . ' has no table; delete it';
		}
	}

	if ( ! empty( $stale ) ) {
		$stale[] = 'Refresh with: bin/check-schema-upgrade.sh --write-snapshots';
	}

	$report( 'Snapshots match a fresh install', $stale );
} catch ( Throwable $e ) {
	$report( 'Schema check ran to completion', array( $e->getMessage() ) );
} finally {
	foreach ( array( TAAS_CHECK_FRESH_DB, TAAS_CHECK_UPGRADE_DB ) as $scratch ) {
		$admin->query( $admin->prepare( 'DROP DATABASE IF EXISTS %i', $scratch ) );
	}
}

if ( ! empty( $failures ) ) {
	echo "\n" . count( $failures ) . " check(s) failed.\n";
	exit( 1 );
}

echo "\nAll schema checks passed.\n";
