<?php
/**
 * Import courses from an XLSX file into the database
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Map each spreadsheet header to the right schema field, and note whether the header is required
 *
 * Notes:
 * - Headers must match exactly, including case and spacing
 * - Any other header is ignored
 * - Columns come from the courses table, except professor_name and professor_email, which
 * come from the professors table and are used to look up professor_ufid
 * - Type, length and default for each column are read from the database, not set here
 * - Order of headers do not matter
 */
const TAAS_COURSE_HEADERS = array(
	// Header           => array( schema_field, required )
	'Prefix'             => array( 'course_code', true ),
	'Title'              => array( 'course_name', true ),
	'Semester'           => array( 'course_semester', true ),
	'Year'               => array( 'course_year', true ),
	'UFO'                => array( 'is_online', true ),
	'Description'        => array( 'course_description', false ),
	'Notes'              => array( 'course_notes', false ),
	'Lab Hour'           => array( 'lab_hours', true ),
	'Current Enrollment' => array( 'enrollment', true ),
	'Maximum Enrollment' => array( 'max_enrollment', true ),
	'Professor Teaching' => array( 'professor_name', true ),
	'GLID'               => array( 'professor_email', true ),
);

/**
 * Match the header row to TAAS_COURSE_HEADERS
 *
 * @param array $cells Header row cells
 * @return array<int, array{header: string, schema_field: string}> Known headers, keyed by cell index
 * @throws RuntimeException If a required header is missing.
 */
function taas_map_headers( array $cells ): array {
	$wanted = array();

	// Create array of TAAS_COURSE_HEADERS for lookup later
	foreach ( TAAS_COURSE_HEADERS as $header => $spec ) {
		$wanted[ $header ] = array(
			'header'       => $header,
			'schema_field' => $spec[0],
			'required'     => $spec[1],
		);
	}

	$map = array();

	// Go through header row and find wanted headers
	foreach ( $cells as $index => $cell ) {
		$key = (string) $cell;

		// If header is found in our wanted array, move header to map array and use index as key
		if ( isset( $wanted[ $key ] ) ) {
			$map[ $index ] = $wanted[ $key ];
			unset( $wanted[ $key ] );
		}
	}

	// Anything left in wanted array was not found in the file
	$missing = array();

	// Check if any required headers were missing
	foreach ( $wanted as $header => $spec ) {
		if ( $spec['required'] ) {
			$missing[] = $header;
		}
	}

	// If required header(s) missing, throw exception and do not ingest file at all
	if ( ! empty( $missing ) ) {
		throw new RuntimeException( 'Missing required headers: ' . esc_html( implode( ', ', $missing ) ) );
	}

	return $map;
}

/**
 * Describe a table's fields as the database schema defines them
 *
 * @param string $table Table name without the WordPress prefix: 'courses' or 'professors'
 * @return array<string, array> SHOW COLUMNS rows (Field, Type, Null, Default, ...), keyed by field name
 * @throws RuntimeException If the table cannot be described.
 */
function taas_table_fields( string $table ): array {
	global $wpdb;

	// Get rows of the table, where each row represents one field of the table and its name, type, etc.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', TAAS_Schema::table( $table ) ), ARRAY_A );

	if ( empty( $rows ) ) {
		throw new RuntimeException( 'Could not read fields for ' . esc_html( $table ) . ': ' . esc_html( $wpdb->last_error ) );
	}

	// Uses each row's 'Field' value as the key
	return array_column( $rows, null, 'Field' );
}

/**
 * Convert a cell to the type its column expects. Handles type verification.
 *
 * @param string $value  Trimmed, non-empty cell value
 * @param array  $field The field's SHOW COLUMNS row
 * @return mixed The converted value, or null if it does not fit the column
 */
function taas_cast_cell( string $value, array $field ) {
	// If this field is course_semester, see if we can find semester by name or number
	if ( 'course_semester' === $field['Field'] ) {
		$semester = TAAS_Semester::try_from_label( $value )
			?? ( ctype_digit( $value ) ? TAAS_Semester::tryFrom( (int) $value ) : null );

		return $semester?->value;
	}

	// If this field is is_online, check which value matches
	if ( 'is_online' === $field['Field'] ) {
		return match ( $value ) {
			'UFO'       => 1,
			'In-person' => 0,
			default     => null,
		};
	}

	// Split the field's type into a name and an optional size, e.g. "varchar(20)" into "varchar" and 20
	$type        = strtolower( $field['Type'] );
	$type_name   = strtok( $type, '( ' );
	$type_size   = preg_match( '/\((\d+)\)/', $type, $match ) ? (int) $match[1] : null;
	$is_unsigned = str_contains( $type, 'unsigned' );

	// Check type of field
	if ( 'year' === $type_name ) {
		// Year fields: a whole number MySQL can store as a year
		$min = 1901;
		$max = 2155;
	} elseif ( 'int' === $type_name ) {
		// Integer fields: a whole number in MySQL's int range
		$min = $is_unsigned ? 0 : -2147483648;
		$max = $is_unsigned ? 4294967295 : 2147483647;
	} else {
		// Text fields: anything fits as long as it is not too long
		if ( null !== $type_size && mb_strlen( $value ) > $type_size ) {
			return null;
		}

		return $value;
	}

	// Check the value is a whole number between min and max
	$number = filter_var(
		$value,
		FILTER_VALIDATE_INT,
		array(
			'options' => array(
				'min_range' => $min,
				'max_range' => $max,
			),
		)
	);

	if ( false === $number ) {
		return null;
	}

	return $number;
}

/**
 * Validate one data row against the database fields
 *
 * A bad or missing value falls back to the column's default if it has one,
 * otherwise the row is rejected. Every problem is reported either way.
 *
 * @param array $cells   Row cells
 * @param array $headers Output of taas_map_headers()
 * @param array $fields Fields keyed by name, from taas_table_fields()
 * @return array{0: array|null, 1: array} The row keyed by field (null if rejected), and its messages
 */
function taas_parse_course_row( array $cells, array $headers, array $fields ): array {
	$data     = array();
	$messages = array();
	$valid    = true;

	// Go through each header of row
	foreach ( $headers as $index => $spec ) {
		$field = $fields[ $spec['schema_field'] ]; // From spreadsheet header specs, get corresponding field from schema and its details (type, etc.)
		$value = trim( (string) ( $cells[ $index ] ?? '' ) ); // Get value at cell
		$cast  = '' === $value ? null : taas_cast_cell( $value, $field ); // Convert value to the field's type

		// If value isn't null, then set field and it's database certified value in data array
		if ( null !== $cast ) {
			$data[ $spec['schema_field'] ] = $cast;
			continue;
		}

		// Otherwise, header value is missing or mismatched
		$problem = '' === $value ? 'Missing value' : 'Type mismatch';

		// Check if header has a default value
		$has_default = null !== $field['Default'] || 'YES' === $field['Null'];

		// Set value to default if header has one
		if ( $has_default ) {
			$data[ $spec['schema_field'] ] = $field['Default'];
		} else {
			$valid = false;
		}

		// Add to messages array what went wrong
		$messages[] = array(
			'header'  => $spec['header'],
			'message' => $problem . ( $has_default ? ', used default' : ', row skipped' ),
		);
	}

	return array( $valid ? $data : null, $messages );
}

/**
 * Import every course in an XLSX file
 *
 * The file's first row holds the headers, in any order. Each row is
 * imported on its own: a bad row is skipped and reported, and the rest carry on.
 *
 * Notes:
 * - Professors must already exist; each row's professor is found by name and GLID (email).
 * - A course with the same term, year, code and name, in the database or earlier in the file, is replaced by the later row.
 *
 * @param string $file_path Path to a .xlsx file
 * @return array{courses: int, replaced: int, skipped: int, messages: array<array{row: int, header: string, message: string}>}
 * @throws InvalidArgumentException If the file is not .xlsx.
 * @throws RuntimeException If the file is unreadable or a required header is missing.
 */
function taas_import_courses( string $file_path ): array {
	global $wpdb;

	if ( 'xlsx' !== strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) ) {
		throw new InvalidArgumentException( 'Unsupported file type. Please use .xlsx.' );
	}

	if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
		throw new RuntimeException( 'File does not exist or cannot be read.' );
	}

	$fields     = taas_table_fields( 'courses' ) + taas_table_fields( 'professors' );
	$courses    = TAAS_Schema::table( 'courses' );
	$professors = TAAS_Schema::table( 'professors' );
	$result     = array(
		'courses'  => 0,
		'replaced' => 0,
		'skipped'  => 0,
		'messages' => array(),
	);

	$spreadsheet = IOFactory::load( $file_path );

	try {
		$sheet       = $spreadsheet->getSheet( 0 );
		$last_column = $sheet->getHighestDataColumn();
		$last_row    = $sheet->getHighestDataRow();
		$headers     = taas_map_headers( $sheet->rangeToArray( "A1:{$last_column}1", null, true, false )[0] );

		// Go through each row and process the course
		for ( $row_number = 2; $row_number <= $last_row; $row_number++ ) {
			$cells = $sheet->rangeToArray( "A{$row_number}:{$last_column}{$row_number}", null, true, false )[0];

			// If the entire row is empty, then skip the row
			if ( empty( array_filter( $cells, fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
				continue;
			}

			list( $course, $messages ) = taas_parse_course_row( $cells, $headers, $fields );

			// If course was successfully parsed
			if ( null !== $course ) {
				// Get professor_ufid
				$course['professor_ufid'] = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT professor_ufid FROM %i WHERE professor_name = %s AND professor_email = %s',
						$professors,
						$course['professor_name'],
						$course['professor_email']
					)
				);

				// Unset these professor_name and professor_email since these aren't fields that a course object has in schema
				unset( $course['professor_name'], $course['professor_email'] );

				// If professor_ufid is not found, skip row
				if ( null === $course['professor_ufid'] ) {
					$messages[] = array(
						'header'  => 'Professor Teaching',
						'message' => 'Professor not found, row skipped',
					);
					$course     = null;
				} else {
					// A course that already exists is replaced by this row, keeping its id so its applications stay linked
					$existing_id = $wpdb->get_var(
						$wpdb->prepare(
							'SELECT course_id FROM %i WHERE course_semester = %d AND course_year = %d AND course_code = %s AND course_name = %s',
							$courses,
							$course['course_semester'],
							$course['course_year'],
							$course['course_code'],
							$course['course_name']
						)
					);

					$saved = null === $existing_id
						? $wpdb->insert( $courses, $course )
						: $wpdb->update( $courses, $course, array( 'course_id' => (int) $existing_id ) );

					// If update/insert failed, skip row
					if ( false === $saved ) {
						$messages[] = array(
							'header'  => 'Prefix',
							'message' => 'Could not save course (' . $wpdb->last_error . '), row skipped',
						);
						$course     = null;
					}
				}
			}

			// Update counts
			if ( null === $course ) {
				++$result['skipped'];
			} else {
				++$result[ null === $existing_id ? 'courses' : 'replaced' ];
			}

			foreach ( $messages as $message ) {
				$result['messages'][] = array( 'row' => $row_number ) + $message;
			}
		}
	} finally {
		$spreadsheet->disconnectWorksheets();
	}

	TAAS_Logger::info(
		'Courses imported.',
		array(
			'courses'  => $result['courses'],
			'replaced' => $result['replaced'],
			'skipped'  => $result['skipped'],
		)
	);

	return $result;
}
