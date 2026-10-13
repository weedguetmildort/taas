<?php
/**
 * Write the live SHOW CREATE TABLE output for each TAAS table to snapshots/<table>.sql
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dump table structure to files
 */
class TAAS_Snapshots {

	/**
	 * Directory holding snapshots, relative to the plugin root
	 */
	private const SNAPSHOT_DIR = 'snapshots/';

	/**
	 * Write a snapshot per table (Local development only)
	 *
	 * @return string[] Bare table names whose snapshot changed
	 */
	public static function export(): array {
		global $wpdb;

		if ( ! TAAS_Auth::is_local_dev() ) {
			TAAS_Logger::warn( 'Snapshot export refused outside local dev.' );
			return array();
		}

		$dir = TAAS_CORE_PATH . self::SNAPSHOT_DIR;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			TAAS_Logger::error( 'Could not create snapshots directory.', array( 'dir' => $dir ) );
			return array();
		}

		$changed = array();

		foreach ( TAAS_Schema::names() as $bare ) {
			if ( ! TAAS_Schema::exists( $bare ) ) {
				continue;
			}

			$table = TAAS_Schema::table( $bare );

			$row = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_N );

			if ( empty( $row[1] ) ) {
				TAAS_Logger::warn( 'Could not read table structure.', array( 'table' => $bare ) );
				continue;
			}

			$ddl  = self::normalise( (string) $row[1] );
			$path = $dir . $bare . '.sql';

			$current = file_exists( $path ) ? (string) file_get_contents( $path ) : '';

			if ( $current === $ddl ) {
				continue;
			}

			file_put_contents( $path, $ddl );

			$changed[] = $bare;
			TAAS_Logger::info( 'Snapshot written.', array( 'table' => $bare ) );
		}

		return $changed;
	}

	/**
	 * Clean DDL so snapshots diff usefully
	 *
	 * @param string $ddl Raw SHOW CREATE TABLE output
	 * @return string
	 */
	private static function normalise( string $ddl ): string {
		$ddl = (string) preg_replace( '/ AUTO_INCREMENT=\d+/', '', $ddl );
		$ddl = str_replace( '`' . TAAS_Schema::prefix(), '`{prefix}', $ddl );

		return rtrim( $ddl ) . "\n";
	}
}
