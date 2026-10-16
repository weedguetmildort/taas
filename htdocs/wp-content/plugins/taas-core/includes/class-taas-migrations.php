<?php
/**
 * Migration runner shared by all TAAS plugins
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Discover, run and record migrations
 */
class TAAS_Migrations {

	/**
	 * Option key prefix for the list of migrations already applied
	 */
	private const OPTION_PREFIX = 'taas_migrations_ran_';

	/**
	 * Run any migrations in $dir that have not run yet
	 *
	 * @param string $component Component key, e.g. 'core'
	 * @param string $dir Absolute path to the migrations directory
	 * @return string[] Ids applied during this call
	 */
	public static function run( string $component, string $dir ): array {
		global $wpdb;

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$applied = self::applied( $component );
		$files   = glob( rtrim( $dir, '/' ) . '/*.php' );

		if ( false === $files || empty( $files ) ) {
			return array();
		}

		// Filenames are zero-padded, so a plain sort gives run order
		sort( $files );

		$just_ran = array();

		foreach ( $files as $file ) {
			$id = basename( $file, '.php' );

			if ( in_array( $id, $applied, true ) ) {
				continue;
			}

			$migration = require $file;

			if ( ! is_callable( $migration ) ) {
				TAAS_Logger::error(
					'Migration did not return a callable.',
					array(
						'component' => $component,
						'migration' => $id,
					)
				);
				continue;
			}

			try {
				$migration( $wpdb );
			} catch ( Throwable $e ) {
				// Stop rather than continue, a later migration may assume this one succeeded
				TAAS_Logger::error(
					'Migration failed, halting.',
					array(
						'component' => $component,
						'migration' => $id,
						'error'     => $e->getMessage(),
					)
				);
				break;
			}

			$applied[]  = $id;
			$just_ran[] = $id;
			self::record( $component, $applied );

			TAAS_Logger::info(
				'Migration applied.',
				array(
					'component' => $component,
					'migration' => $id,
				)
			);
		}

		return $just_ran;
	}

	/**
	 * Return the ids of migrations already applied for a component
	 *
	 * @param string $component Component key
	 * @return string[]
	 */
	public static function applied( string $component ): array {
		$stored = get_option( self::OPTION_PREFIX . $component, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Return migration ids present on disk but not yet applied
	 *
	 * @param string $component Component key
	 * @param string $dir       Absolute path to the migrations directory
	 * @return string[]
	 */
	public static function pending( string $component, string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( rtrim( $dir, '/' ) . '/*.php' );

		if ( false === $files ) {
			return array();
		}

		$applied = self::applied( $component );
		$pending = array();

		foreach ( $files as $file ) {
			$id = basename( $file, '.php' );

			if ( ! in_array( $id, $applied, true ) ) {
				$pending[] = $id;
			}
		}

		sort( $pending );

		return $pending;
	}

	/**
	 * Store the applied list for a component
	 *
	 * @param string   $component Component key
	 * @param string[] $applied   Migration ids
	 * @return void
	 */
	private static function record( string $component, array $applied ): void {
		update_option( self::OPTION_PREFIX . $component, array_values( array_unique( $applied ) ), false );
	}

	/**
	 * Mark every migration on disk as applied without running any
	 *
	 * @param string $component Component key
	 * @param string $dir Absolute path to the migrations directory
	 * @return int Number marked
	 */
	public static function mark_all_applied( string $component, string $dir ): int {
		if ( ! TAAS_Auth::is_local_dev() ) {
			TAAS_Logger::warn( 'mark_all_applied refused outside local dev.' );
			return 0;
		}

		$pending = self::pending( $component, $dir );

		if ( empty( $pending ) ) {
			return 0;
		}

		self::record( $component, array_merge( self::applied( $component ), $pending ) );

		return count( $pending );
	}
}
