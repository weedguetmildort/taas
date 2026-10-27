<?php
/**
 * Import courses and their professors from a CSV or XLSX file
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Yield each data row of a CSV or XLSX file, skipping the header and empty rows
 *
 * @param string $file_path Path to a .csv or .xlsx file
 * @return Generator<int, array> Raw cell values, columns A through H
 * @throws RuntimeException If the CSV cannot be opened.
 * @throws InvalidArgumentException If the file type is not supported.
 */
function taas_read_course_rows( string $file_path ): Generator {
	$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

	if ( 'csv' === $extension ) {
		$file = fopen( $file_path, 'r' );

		if ( false === $file ) {
			throw new RuntimeException( 'Cannot open CSV file.' );
		}

		try {
			// Skip header
			fgetcsv( $file );

			while ( true ) {
				$row = fgetcsv( $file );

				if ( false === $row ) {
					break;
				}

				if ( array( null ) === $row ) {
					continue;
				}

				yield $row;
			}
		} finally {
			fclose( $file );
		}
	} elseif ( 'xlsx' === $extension ) {
		$spreadsheet = IOFactory::load( $file_path );

		try {
			// Read the first worksheet, row 1 holds the headers
			$worksheet = $spreadsheet->getSheet( 0 );
			$last_row  = $worksheet->getHighestDataRow();

			for ( $row_number = 2; $row_number <= $last_row; $row_number++ ) {
				$row = $worksheet->rangeToArray( "A{$row_number}:H{$row_number}", null, true, false )[0];

				// Skip empty rows
				$filled = array_filter(
					$row,
					fn( $value ) => null !== $value && '' !== trim( (string) $value )
				);

				if ( empty( $filled ) ) {
					continue;
				}

				yield $row;
			}
		} finally {
			$spreadsheet->disconnectWorksheets();
		}
	} else {
		throw new InvalidArgumentException( 'Unsupported file type. Please use .csv or .xlsx.' );
	}
}

/**
 * Read the spreadsheet's UFO (UF Online) cell as a yes/no flag
 *
 * @param string $value Trimmed cell value
 * @return bool True if the course is online
 * @throws RuntimeException If the value is not a recognised yes/no.
 */
function taas_parse_online_flag( string $value ): bool {
	$normalised = strtoupper( trim( $value ) );

	if ( in_array( $normalised, array( '', '0', 'N', 'NO', 'FALSE' ), true ) ) {
		return false;
	}

	if ( in_array( $normalised, array( '1', 'Y', 'YES', 'TRUE', 'X', 'UFO', 'ONLINE' ), true ) ) {
		return true;
	}

	throw new RuntimeException( 'Invalid UFO value: "' . esc_html( $value ) . '". Expected Y/N, yes/no, true/false, 1/0 or blank.' );
}

/**
 * Import every course in a file, creating professors that do not exist yet
 *
 * Runs in a single transaction, so a failure on any row saves nothing.
 * TAAS_Guard::year() throws InvalidArgumentException, before the transaction
 * starts, if the year does not fit the course_year column.
 *
 * @param string        $file_path   Path to a .csv or .xlsx file
 * @param TAAS_Semester $semester    Semester the courses belong to
 * @param int           $course_year Year the courses belong to, e.g. 2026
 * @return array{professors: int, courses: int} Rows inserted, keyed by table
 * @throws RuntimeException If the file is unreadable, a row is invalid, or a query fails.
 * @throws Throwable Any other failure, rethrown after rolling back.
 */
function taas_import_courses( string $file_path, TAAS_Semester $semester, int $course_year ): array {
	global $wpdb;

	if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
		throw new RuntimeException( 'File does not exist or cannot be read.' );
	}

	// TODO: semester and year should come from input fields on the admin's frontend page
	TAAS_Guard::year( 'course_year', $course_year );

	$professors = TAAS_Schema::table( 'professors' );
	$courses    = TAAS_Schema::table( 'courses' );
	$counts     = array(
		'professors' => 0,
		'courses'    => 0,
	);

	try {
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'Could not start transaction: ' . $wpdb->last_error );
		}

		// Find the next available IDs
		$next_professor_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(MAX(professor_ufid), 0) + 1 FROM %i', $professors )
		);

		$next_course_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(MAX(course_id), 0) + 1 FROM %i', $courses )
		);

		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not retrieve next IDs: ' . $wpdb->last_error );
		}

		foreach ( taas_read_course_rows( $file_path ) as $row ) {
			if ( 8 !== count( $row ) ) {
				throw new RuntimeException( 'Invalid row: expected 8 columns, got ' . count( $row ) );
			}

			list(
				$course_code,
				$ufo, // UF Online flag, stored as is_online
				$lab_hours,
				$course_name,
				$enrollment,
				$max_enrollment,
				$professor_name,
				$professor_email
			) = array_map(
				fn( $value ) => trim( (string) ( $value ?? '' ) ),
				$row
			);

			$professor_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT professor_ufid FROM %i WHERE professor_name = %s AND professor_email = %s',
					$professors,
					$professor_name,
					$professor_email
				)
			);

			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Error finding professor: ' . $wpdb->last_error );
			}

			// Create the professor if they do not exist yet
			if ( null === $professor_id ) {
				$professor_id = $next_professor_id;

				$result = $wpdb->insert(
					$professors,
					array(
						'professor_ufid'  => $professor_id,
						'professor_name'  => $professor_name,
						'professor_email' => $professor_email,
					),
					array( '%s', '%s', '%s' )
				);

				if ( false === $result ) {
					throw new RuntimeException( 'Error inserting professor: ' . $wpdb->last_error );
				}

				++$next_professor_id;
				++$counts['professors'];
			}

			$result = $wpdb->insert(
				$courses,
				array(
					'course_id'       => $next_course_id,
					'course_semester' => $semester->value,
					'course_year'     => $course_year,
					'is_online'       => taas_parse_online_flag( $ufo ) ? 1 : 0,
					'course_code'     => $course_code,
					'course_name'     => $course_name,
					'lab_hours'       => $lab_hours,
					'enrollment'      => $enrollment,
					'max_enrollment'  => $max_enrollment,
					'professor_ufid'  => $professor_id,
				),
				array( '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s' )
			);

			if ( false === $result ) {
				throw new RuntimeException( 'Error inserting course: ' . $wpdb->last_error );
			}

			++$next_course_id;
			++$counts['courses'];
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'Error committing transaction: ' . $wpdb->last_error );
		}
	} catch ( Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		throw $e;
	}

	TAAS_Logger::info( 'Courses imported.', $counts );

	return $counts;
}
