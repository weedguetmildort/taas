<?php
/**
 * Seed data importer
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import seed rows from JSON
 */
class TAAS_Seeds {

	/**
	 * Hold seed files, relative to the plugin root
	 */
	private const SEED_DIR = 'seeds/';

	/**
	 * Decide if a row already exists based on natural key columns per table 
	 *
	 * @var array<string, string[]>
	 */
	private const NATURAL_KEYS = array(
		'professors'          => array( 'professor_ufid' ),
		'students'            => array( 'student_ufid' ),
		'student_profiles'    => array( 'student_ufid' ),
		'academic_background' => array( 'student_ufid', 'university', 'degree_type' ),
		'courses'             => array( 'course_id' ),
		'applications'        => array( 'student_ufid', 'applied_course_code' ),
	);

	/**
	 * Stampe columns with the current UTC time if the seed row omits them
	 *
	 * @var array<string, string>
	 */
	private const TIMESTAMPS = array();

	/**
	 * Import one seed file
	 *
	 * @param string $file Seed file name, e.g. 'demo-data.json'
	 * @return array<string, int> Rows inserted, keyed by table
	 */
	public static function import( string $file ): array {
		global $wpdb;

		$path = TAAS_CORE_PATH . self::SEED_DIR . $file;

		if ( ! file_exists( $path ) ) {
			TAAS_Logger::warn( 'Seed file missing.', array( 'file' => $file ) );
			return array();
		}

		$raw = file_get_contents( $path );

		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) ) {
			TAAS_Logger::error( 'Seed file is not valid JSON.', array( 'file' => $file ) );
			return array();
		}

		$inserted = array();

		foreach ( TAAS_Schema::names() as $bare ) {
			if ( empty( $data[ $bare ] ) || ! is_array( $data[ $bare ] ) ) {
				continue;
			}

			if ( ! TAAS_Schema::exists( $bare ) ) {
				TAAS_Logger::warn( 'Seed target table missing.', array( 'table' => $bare ) );
				continue;
			}

			$count = 0;

			foreach ( $data[ $bare ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				if ( self::row_exists( $bare, $row ) ) {
					continue;
				}

				$row = self::stamp( $bare, $row );

				$result = $wpdb->insert( TAAS_Schema::table( $bare ), $row );

				if ( false === $result ) {
					TAAS_Logger::error(
						'Seed insert failed.',
						array(
							'table'    => $bare,
							'db_error' => $wpdb->last_error,
						)
					);
					continue;
				}

				++$count;
			}

			if ( $count > 0 ) {
				$inserted[ $bare ] = $count;
			}
		}

		if ( ! empty( $inserted ) ) {
			TAAS_Logger::info( 'Seed data imported.', $inserted );
		}

		return $inserted;
	}

	/**
	 * Check whether a row with this natural key already exists
	 *
	 * @param string $bare Bare table name.
	 * @param array  $row  Row data.
	 * @return bool
	 */
	private static function row_exists( string $bare, array $row ): bool {
		global $wpdb;

		$keys = self::NATURAL_KEYS[ $bare ] ?? array();

		if ( empty( $keys ) ) {
			return false;
		}

		$where  = array();
		$values = array();

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				// Treat unidentified row as new
				return false;
			}

			$where[]  = "{$key} = %s";
			$values[] = (string) $row[ $key ];
		}

		$table = TAAS_Schema::table( $bare );
		$sql   = "SELECT 1 FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT 1';
		
		return null !== $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Add a timestamp column if the table has one and the row omits it
	 *
	 * @param string $bare Bare table name
	 * @param array  $row  Row data
	 * @return array
	 */
	private static function stamp( string $bare, array $row ): array {
		$column = self::TIMESTAMPS[ $bare ] ?? '';

		if ( '' !== $column && ! isset( $row[ $column ] ) ) {
			$row[ $column ] = current_time( 'mysql', true );
		}

		return $row;
	}

	/**
	 * Delete every row from every table, children first
	 *
	 * @return void
	 */
	public static function truncate_all(): void {
		global $wpdb;

		if ( ! TAAS_Auth::is_local_dev() ) {
			TAAS_Logger::warn( 'truncate_all refused outside local dev.' );
			return;
		}

		foreach ( array_reverse( TAAS_Schema::names() ) as $bare ) {
			if ( ! TAAS_Schema::exists( $bare ) ) {
				continue;
			}

			$table = TAAS_Schema::table( $bare );

			$wpdb->query( "DELETE FROM {$table}" );
		}

		TAAS_Logger::info( 'All TAAS tables truncated.' );
	}
}