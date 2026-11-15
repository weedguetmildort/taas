<?php
/**
 * Export a term's courses, applicants, preferences and matches as CSV files
 *
 * Notes:
 * - Every export covers one term, which is in the filename. Course columns use the same
 *   headers as course import (func-taas-course-ingest.php), so a course export can be imported again.
 * - Repeated groups (TA 1, TA 2, ...) are spread across columns. A file has as many groups as its
 *   longest row needs, but never fewer than TAAS_EXPORT_TYPES gives. Unused cells are blank.
 * - Saved matches are applications with match_status Matched. Recommendations are what
 *   the last matching run saved to match_recommendations.
 * - Withdrawn applications are left out everywhere.
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every export, and the fewest repeated column groups it has
 */
const TAAS_EXPORT_TYPES = array(
	// Slug                => fewest groups
	'course-staffing'       => 5,
	'match-recommendations' => 5,
	'ta-assignments'        => 2,
	'student-preferences'   => 5,
	'instructor-rankings'   => 9,
);

/**
 * Build one export for a term
 *
 * @param string        $type     Slug from TAAS_EXPORT_TYPES
 * @param TAAS_Semester $semester Semester of the term
 * @param int           $year     Year of the term
 * @return array{filename: string, csv: string}
 * @throws InvalidArgumentException If the type is unknown. A failed query throws RuntimeException.
 */
function taas_export( string $type, TAAS_Semester $semester, int $year ): array {
	if ( ! isset( TAAS_EXPORT_TYPES[ $type ] ) ) {
		throw new InvalidArgumentException( 'Unknown export: ' . esc_html( $type ) );
	}

	$courses = taas_export_courses( $semester, $year );

	$rows = match ( $type ) {
		'course-staffing'       => taas_export_course_staffing( $courses, taas_export_applications( $semester, $year ) ),
		'match-recommendations' => taas_export_match_recommendations( $courses, taas_export_recommendations( $semester, $year ) ),
		'ta-assignments'        => taas_export_ta_assignments( $courses, taas_export_students( $semester, $year ), taas_export_applications( $semester, $year ) ),
		'student-preferences'   => taas_export_student_preferences( $courses, taas_export_students( $semester, $year ), taas_export_applications( $semester, $year ) ),
		'instructor-rankings'   => taas_export_instructor_rankings( $courses, taas_export_applications( $semester, $year ) ),
	};

	return array(
		'filename' => sprintf( 'taas-%s-%s-%d.csv', $type, strtolower( str_replace( ' ', '-', $semester->label() ) ), $year ),
		'csv'      => taas_export_csv( $rows ),
	);
}

/**
 * Run a prepared SELECT
 *
 * @param string $sql Query, already passed through $wpdb->prepare()
 * @return array<int, array<string, string|null>>
 * @throws RuntimeException If the query fails.
 */
function taas_export_query( string $sql ): array {
	global $wpdb;

	$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Every caller prepares the query, and exports must read the current rows.

	if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
		throw new RuntimeException( 'Export query failed: ' . esc_html( $wpdb->last_error ) );
	}

	return $rows;
}

/**
 * A term's courses with their professors, by code, title and delivery mode
 *
 * @param TAAS_Semester $semester Semester of the term
 * @param int           $year     Year of the term
 * @return array<int, array<string, string|null>>
 * @throws RuntimeException If the query fails.
 */
function taas_export_courses( TAAS_Semester $semester, int $year ): array {
	global $wpdb;

	return taas_export_query(
		$wpdb->prepare(
			'SELECT c.course_id, c.course_code, c.course_name, c.course_semester, c.course_year, c.is_online,'
			. ' c.lab_hours, c.enrollment, c.max_enrollment, c.weekly_hours, p.professor_name, p.professor_email'
			. ' FROM %i c INNER JOIN %i p ON p.professor_id = c.professor_id'
			. ' WHERE c.course_semester = %d AND c.course_year = %d'
			. ' ORDER BY c.course_code, c.course_name, c.is_online, c.course_id',
			TAAS_Schema::table( 'courses' ),
			TAAS_Schema::table( 'professors' ),
			$semester->value,
			$year
		)
	);
}

/**
 * A term's applications that are not withdrawn, with each student's name and email
 *
 * @param TAAS_Semester $semester Semester of the term
 * @param int           $year     Year of the term
 * @return array<int, array<string, string|null>>
 * @throws RuntimeException If the query fails.
 */
function taas_export_applications( TAAS_Semester $semester, int $year ): array {
	global $wpdb;

	return taas_export_query(
		$wpdb->prepare(
			'SELECT a.applied_course_id AS course_id, a.student_ufid, s.student_name, s.student_email,'
			. ' a.student_preference_rank, a.professor_preference_rank, a.match_status, a.assigned_hours'
			. ' FROM %i a'
			. ' INNER JOIN %i c ON c.course_id = a.applied_course_id'
			. ' INNER JOIN %i s ON s.student_ufid = a.student_ufid'
			. ' WHERE c.course_semester = %d AND c.course_year = %d AND a.match_status <> %d'
			. ' ORDER BY a.student_ufid, a.application_id',
			TAAS_Schema::table( 'applications' ),
			TAAS_Schema::table( 'courses' ),
			TAAS_Schema::table( 'students' ),
			$semester->value,
			$year,
			TAAS_Match_Status::Withdrawn->value
		)
	);
}

/**
 * A term's applicants, with their profiles and HR decisions, by UFID
 *
 * An applicant is a student with an application in the term that is not withdrawn.
 *
 * @param TAAS_Semester $semester Semester of the term
 * @param int           $year     Year of the term
 * @return array<int, array<string, string|null>>
 * @throws RuntimeException If the query fails.
 */
function taas_export_students( TAAS_Semester $semester, int $year ): array {
	global $wpdb;

	return taas_export_query(
		$wpdb->prepare(
			'SELECT s.student_ufid, s.student_name, s.student_email, p.enrollment_status, p.admitted_semester,'
			. ' p.gpa, p.english_test_score, p.country_code, p.available_hours, r.decision'
			. ' FROM %i s'
			. ' LEFT JOIN %i p ON p.student_ufid = s.student_ufid'
			. ' LEFT JOIN %i sub ON sub.student_ufid = s.student_ufid AND sub.semester = %d AND sub.year = %d'
			. ' LEFT JOIN %i r ON r.submission_id = sub.submission_id'
			. ' WHERE s.student_ufid IN ('
			. 'SELECT a.student_ufid FROM %i a INNER JOIN %i c ON c.course_id = a.applied_course_id'
			. ' WHERE c.course_semester = %d AND c.course_year = %d AND a.match_status <> %d'
			. ') ORDER BY s.student_ufid',
			TAAS_Schema::table( 'students' ),
			TAAS_Schema::table( 'student_profiles' ),
			TAAS_Schema::table( 'student_submissions' ),
			$semester->value,
			$year,
			TAAS_Schema::table( 'hr_reviews' ),
			TAAS_Schema::table( 'applications' ),
			TAAS_Schema::table( 'courses' ),
			$semester->value,
			$year,
			TAAS_Match_Status::Withdrawn->value
		)
	);
}

/**
 * The last matching run's recommendations for a term's courses, in the order they were made
 *
 * @param TAAS_Semester $semester Semester of the term
 * @param int           $year     Year of the term
 * @return array<int, array<string, string|null>>
 * @throws RuntimeException If the query fails.
 */
function taas_export_recommendations( TAAS_Semester $semester, int $year ): array {
	global $wpdb;

	return taas_export_query(
		$wpdb->prepare(
			'SELECT r.course_id, r.student_ufid, s.student_name, s.student_email, r.recommended_hours, r.reason'
			. ' FROM %i r'
			. ' INNER JOIN %i c ON c.course_id = r.course_id'
			. ' INNER JOIN %i s ON s.student_ufid = r.student_ufid'
			. ' WHERE c.course_semester = %d AND c.course_year = %d'
			. ' ORDER BY r.recommendation_id',
			TAAS_Schema::table( 'match_recommendations' ),
			TAAS_Schema::table( 'courses' ),
			TAAS_Schema::table( 'students' ),
			$semester->value,
			$year
		)
	);
}

/**
 * Course Staffing: one row per course, with the TAs saved to it
 *
 * @param array $courses      Rows from taas_export_courses()
 * @param array $applications Rows from taas_export_applications()
 * @return array<int, array<int, string>> Header row, then one row per course
 */
function taas_export_course_staffing( array $courses, array $applications ): array {
	$by_course = array();

	foreach ( $applications as $app ) {
		if ( TAAS_Match_Status::Matched->value === (int) $app['match_status'] ) {
			$by_course[ $app['course_id'] ][] = $app;
		}
	}

	$records = array();

	foreach ( $courses as $course ) {
		$tas = $by_course[ $course['course_id'] ] ?? array();

		// Most hours first, then by UFID
		usort( $tas, fn( $a, $b ) => array( - (int) $a['assigned_hours'], $a['student_ufid'] ) <=> array( - (int) $b['assigned_hours'], $b['student_ufid'] ) );

		$records[] = array(
			taas_export_course_columns( $course ) + taas_export_hours_columns( $course, 'TA Hours Assigned', array_column( $tas, 'assigned_hours' ) ),
			array_map( fn( $ta ) => array( $ta['student_ufid'], $ta['student_email'], $ta['student_name'], $ta['assigned_hours'] ), $tas ),
		);
	}

	return taas_export_table(
		array_keys( taas_export_course_columns( array() ) + taas_export_hours_columns( array(), 'TA Hours Assigned', array() ) ),
		array( 'TA %d UFID', 'TA %d Email', 'TA %d Name', 'TA %d Hours' ),
		TAAS_EXPORT_TYPES['course-staffing'],
		$records
	);
}

/**
 * Match Recommendations: Course Staffing's layout, with the last matching run's recommendations in place of saved matches
 *
 * TAs are in the order the algorithm picked them, and each has the reason it picked them.
 *
 * @param array $courses         Rows from taas_export_courses()
 * @param array $recommendations Rows from taas_export_recommendations()
 * @return array<int, array<int, string>> Header row, then one row per course
 */
function taas_export_match_recommendations( array $courses, array $recommendations ): array {
	$by_course = array();

	foreach ( $recommendations as $recommendation ) {
		$by_course[ $recommendation['course_id'] ][] = $recommendation;
	}

	$records = array();

	foreach ( $courses as $course ) {
		$tas = $by_course[ $course['course_id'] ] ?? array();

		$records[] = array(
			taas_export_course_columns( $course ) + taas_export_hours_columns( $course, 'TA Hours Recommended', array_column( $tas, 'recommended_hours' ) ),
			array_map( fn( $ta ) => array( $ta['student_ufid'], $ta['student_email'], $ta['student_name'], $ta['recommended_hours'], taas_export_reason( (string) $ta['reason'] ) ), $tas ),
		);
	}

	return taas_export_table(
		array_keys( taas_export_course_columns( array() ) + taas_export_hours_columns( array(), 'TA Hours Recommended', array() ) ),
		array( 'TA %d UFID', 'TA %d Email', 'TA %d Name', 'TA %d Hours', 'TA %d Reason' ),
		TAAS_EXPORT_TYPES['match-recommendations'],
		$records
	);
}

/**
 * TA Assignments: one row per applicant, with the courses they were saved to
 *
 * @param array $courses      Rows from taas_export_courses()
 * @param array $students     Rows from taas_export_students()
 * @param array $applications Rows from taas_export_applications()
 * @return array<int, array<int, string>> Header row, then one row per applicant
 */
function taas_export_ta_assignments( array $courses, array $students, array $applications ): array {
	$courses_by_id = array_column( $courses, null, 'course_id' );
	$course_order  = array_flip( array_keys( $courses_by_id ) );
	$by_student    = array();

	foreach ( $applications as $app ) {
		if ( TAAS_Match_Status::Matched->value === (int) $app['match_status'] ) {
			$by_student[ $app['student_ufid'] ][] = $app;
		}
	}

	$records = array();

	foreach ( $students as $student ) {
		$matches = $by_student[ $student['student_ufid'] ] ?? array();

		// Same order as the course exports
		usort( $matches, fn( $a, $b ) => $course_order[ $a['course_id'] ] <=> $course_order[ $b['course_id'] ] );

		$assigned  = array_sum( array_map( 'intval', array_column( $matches, 'assigned_hours' ) ) );
		$available = $student['available_hours'];

		if ( empty( $matches ) ) {
			$status = 'Unassigned';
		} elseif ( null !== $available && $assigned < (int) $available ) {
			$status = 'Partially Assigned';
		} else {
			$status = 'Assigned';
		}

		$records[] = array(
			taas_export_student_columns( $student ) + array(
				'Hours Assigned'    => $assigned,
				'Assignment Status' => $status,
			),
			array_map( fn( $app ) => array( taas_export_course_label( $courses_by_id[ $app['course_id'] ] ), $app['assigned_hours'] ), $matches ),
		);
	}

	return taas_export_table(
		array_merge( array_keys( taas_export_student_columns( array() ) ), array( 'Hours Assigned', 'Assignment Status' ) ),
		array( 'Course %d', 'Course %d Hours' ),
		TAAS_EXPORT_TYPES['ta-assignments'],
		$records
	);
}

/**
 * Student Preferences: one row per applicant, with the courses they ranked, in rank order
 *
 * Applications the student did not rank are left out.
 *
 * @param array $courses      Rows from taas_export_courses()
 * @param array $students     Rows from taas_export_students()
 * @param array $applications Rows from taas_export_applications()
 * @return array<int, array<int, string>> Header row, then one row per applicant
 */
function taas_export_student_preferences( array $courses, array $students, array $applications ): array {
	$courses_by_id = array_column( $courses, null, 'course_id' );
	$by_student    = array();

	foreach ( $applications as $app ) {
		if ( null !== $app['student_preference_rank'] ) {
			$by_student[ $app['student_ufid'] ][] = $app;
		}
	}

	$records = array();

	foreach ( $students as $student ) {
		$ranked = $by_student[ $student['student_ufid'] ] ?? array();

		usort( $ranked, fn( $a, $b ) => (int) $a['student_preference_rank'] <=> (int) $b['student_preference_rank'] );

		$records[] = array(
			taas_export_student_columns( $student ),
			array_map( fn( $app ) => array( taas_export_course_label( $courses_by_id[ $app['course_id'] ] ) ), $ranked ),
		);
	}

	return taas_export_table(
		array_keys( taas_export_student_columns( array() ) ),
		array( 'Preference %d' ),
		TAAS_EXPORT_TYPES['student-preferences'],
		$records
	);
}

/**
 * Instructor Rankings: one row per course, with the applicants its professor ranked, in rank order
 *
 * @param array $courses      Rows from taas_export_courses()
 * @param array $applications Rows from taas_export_applications()
 * @return array<int, array<int, string>> Header row, then one row per course
 */
function taas_export_instructor_rankings( array $courses, array $applications ): array {
	$by_course = array();

	foreach ( $applications as $app ) {
		if ( null !== $app['professor_preference_rank'] ) {
			$by_course[ $app['course_id'] ][] = $app;
		}
	}

	$records = array();

	foreach ( $courses as $course ) {
		$ranked = $by_course[ $course['course_id'] ] ?? array();

		usort( $ranked, fn( $a, $b ) => array( (int) $a['professor_preference_rank'], $a['student_ufid'] ) <=> array( (int) $b['professor_preference_rank'], $b['student_ufid'] ) );

		$columns = taas_export_course_columns( $course );
		unset( $columns['Maximum Enrollment'] );

		$records[] = array(
			$columns,
			array_map( fn( $app ) => array( $app['student_ufid'], $app['student_email'], $app['student_name'] ), $ranked ),
		);
	}

	$headers = array_keys( taas_export_course_columns( array() ) );

	return taas_export_table(
		array_values( array_diff( $headers, array( 'Maximum Enrollment' ) ) ),
		array( 'Pref %d UFID', 'Pref %d Email', 'Pref %d Name' ),
		TAAS_EXPORT_TYPES['instructor-rankings'],
		$records
	);
}

/**
 * A course's columns, under the course import headers
 *
 * @param array $course Row from taas_export_courses(), or an empty array for just the headers
 * @return array<string, mixed> Header => value
 */
function taas_export_course_columns( array $course ): array {
	$semester = TAAS_Semester::tryFrom( (int) ( $course['course_semester'] ?? 0 ) );
	$online   = $course['is_online'] ?? null;

	return array(
		'Prefix'             => $course['course_code'] ?? null,
		'Title'              => $course['course_name'] ?? null,
		'Semester'           => $semester?->label(),
		'Year'               => $course['course_year'] ?? null,
		'UFO'                => null === $online ? null : ( (int) $online ? 'UFO' : 'In-person' ),
		'Lab Hour'           => $course['lab_hours'] ?? null,
		'Current Enrollment' => $course['enrollment'] ?? null,
		'Maximum Enrollment' => $course['max_enrollment'] ?? null,
		'Professor Teaching' => $course['professor_name'] ?? null,
		'GLID'               => $course['professor_email'] ?? null,
	);
}

/**
 * A course's needed, given and remaining TA hours
 *
 * @param array  $course Row from taas_export_courses(), or an empty array for just the headers
 * @param string $header Header for the hours given
 * @param array  $hours  Hours given by each TA
 * @return array<string, mixed> Header => value. Hours left is blank when the course's hours are unknown
 */
function taas_export_hours_columns( array $course, string $header, array $hours ): array {
	$needed = $course['weekly_hours'] ?? null;
	$given  = array_sum( array_map( 'intval', $hours ) );

	return array(
		'Course TA Hours' => $needed,
		$header           => $given,
		'TA Hours Left'   => null === $needed ? null : (int) $needed - $given,
	);
}

/**
 * An applicant's columns, shared by TA Assignments and Student Preferences
 *
 * @param array $student Row from taas_export_students(), or an empty array for just the headers
 * @return array<string, mixed> Header => value
 */
function taas_export_student_columns( array $student ): array {
	$program  = $student['enrollment_status'] ?? null;
	$score    = $student['english_test_score'] ?? null;
	$gpa      = $student['gpa'] ?? null;
	$decision = $student['decision'] ?? null;

	return array(
		'UFID'               => $student['student_ufid'] ?? null,
		'UF Email'           => $student['student_email'] ?? null,
		'Student Name'       => $student['student_name'] ?? null,
		'Program'            => null === $program ? null : TAAS_Enrollment_Status::tryFrom( (int) $program )?->label(),
		'Semester Admitted'  => $student['admitted_semester'] ?? null,
		'GPA'                => null === $gpa ? null : number_format( (float) $gpa, 2, '.', '' ),
		'English Test Score' => null === $score ? null : TAAS_English_Test_Score::tryFrom( (int) $score )?->label(),
		'Country'            => $student['country_code'] ?? null,
		'HR Decision'        => match ( $decision ) {
			'approve' => 'Approved',
			'reject'  => 'Rejected',
			default   => empty( $student ) ? null : 'Unreviewed',
		},
		'TA Hours'           => $student['available_hours'] ?? null,
	);
}

/**
 * A course's name in one cell, e.g. "CAP3027 - Intro Computational Media (UFO) - Zhang, Rong"
 *
 * @param array $course Row from taas_export_courses()
 * @return string
 */
function taas_export_course_label( array $course ): string {
	$online = (int) $course['is_online'] ? ' (UFO)' : '';

	return "{$course['course_code']} - {$course['course_name']}{$online} - {$course['professor_name']}";
}

/**
 * The reason the matching algorithm gave for a recommendation, in words
 *
 * @param string $reason Saved reason, e.g. professor_preference_1
 * @return string The saved reason itself if it isn't one the algorithm gives
 */
function taas_export_reason( string $reason ): string {
	if ( preg_match( '/^(professor|student)_preference_(\d+)$/', $reason, $match ) ) {
		return ucfirst( $match[1] ) . ' preference ' . $match[2];
	}

	return match ( $reason ) {
		'ta_for_course' => 'Has TA\'d this course',
		'taken_course'  => 'Has taken this course',
		'ta_experience' => 'Has TA experience',
		'applied'       => 'Applied',
		default         => $reason,
	};
}

/**
 * Lay records out as a table, spreading each record's groups across numbered columns
 *
 * @param string[] $headers       Headers of the columns before the groups
 * @param string[] $group_headers Headers of one group, with %d for its number, e.g. 'TA %d UFID'
 * @param int      $min_groups    Fewest groups to give columns to
 * @param array    $records       List of array{0: array<string, mixed>, 1: list<list<mixed>>}: columns before the groups, then the groups
 * @return array<int, array<int, string>> Header row, then one row per record
 */
function taas_export_table( array $headers, array $group_headers, int $min_groups, array $records ): array {
	$groups = $min_groups;

	foreach ( $records as $record ) {
		$groups = max( $groups, count( $record[1] ) );
	}

	$header_row = $headers;

	for ( $n = 1; $n <= $groups; $n++ ) {
		foreach ( $group_headers as $group_header ) {
			$header_row[] = sprintf( $group_header, $n );
		}
	}

	$rows = array( $header_row );

	foreach ( $records as list( $columns, $record_groups ) ) {
		$row = array_map( fn( $header ) => $columns[ $header ], $headers );

		foreach ( $record_groups as $group ) {
			array_push( $row, ...$group );
		}

		$row    = array_pad( $row, count( $header_row ), null );
		$rows[] = array_map( fn( $cell ) => null === $cell ? '' : (string) $cell, $row );
	}

	return $rows;
}

/**
 * Write rows as CSV: UTF-8 with a byte order mark so Excel reads it as UTF-8, CRLF line endings,
 * and RFC 4180 quoting (fputcsv also quotes fields with spaces)
 *
 * @param array<int, array<int, string>> $rows Header row, then data rows
 * @return string
 */
function taas_export_csv( array $rows ): string {
	$handle = fopen( 'php://temp', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream, not a file.

	foreach ( $rows as $row ) {
		fputcsv( $handle, $row, ',', '"', '', "\r\n" );
	}

	rewind( $handle );
	$csv = (string) stream_get_contents( $handle );
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream, not a file.

	return "\xEF\xBB\xBF" . $csv;
}
