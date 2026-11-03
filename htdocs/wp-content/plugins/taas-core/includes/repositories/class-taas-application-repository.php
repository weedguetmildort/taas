<?php
/**
 * Repository for the applications table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- Public contracts document exceptions propagated by guards and persistence helpers too.

/**
 * Data access for applications, keyed by application_id.
 */
final class TAAS_Application_Repository extends TAAS_Repository {

	/**
	 * The student's choices for a term, including withdrawn records.
	 *
	 * When $for_update is true, call inside a transaction; locks remain until
	 * commit or rollback. Without a transaction they end with this statement.
	 * Authentication, ownership authorization and deadlines belong to callers.
	 *
	 * @param string        $student_ufid Verified owner.
	 * @param TAAS_Semester $semester     Application semester.
	 * @param int           $year         Application year.
	 * @param bool          $for_update Lock matched rows inside an edit transaction.
	 * @return list<array{
	 *   application_id: string, student_ufid: string, applied_course_id: string,
	 *   has_taken_course: string, has_ta_for_course: string,
	 *   student_statement: string|null, student_preference_rank: string|null,
	 *   professor_preference_rank: string|null, match_status: string, assigned_hours: string
	 * }> Raw database values, ordered by student preference and application ID.
	 * @throws InvalidArgumentException If validation fails.
	 * @throws RuntimeException If a database operation fails.
	 */
	public function find_by_student_and_term( string $student_ufid, TAAS_Semester $semester, int $year, bool $for_update = false ): array {
		global $wpdb;
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::year( 'year', $year );
		return $this->select_rows(
			$wpdb->prepare(
				'SELECT a.* FROM %i a INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' WHERE a.student_ufid = %s AND c.course_semester = %d AND c.course_year = %d'
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Conditional suffix is a fixed SQL literal; all values are bound.
				. ' ORDER BY a.student_preference_rank, a.application_id' . ( $for_update ? ' FOR UPDATE' : '' ),
				$this->table(),
				TAAS_Schema::table( 'courses' ),
				$student_ufid,
				$semester->value,
				$year
			)
		);
	}

	/**
	 * Change student-owned fields without overwriting a professor's ranking.
	 *
	 * Call after locking and validating the owner's term rows in a transaction.
	 *
	 * @param string            $student_ufid Verified owner.
	 * @param int               $application_id Owned application ID.
	 * @param int|null          $rank Student rank; null retains the previous rank.
	 * @param TAAS_Match_Status $status Pending or Withdrawn.
	 * @param bool|null         $has_ta_for_course Previous TA declaration; null preserves it.
	 * @return void
	 * @throws InvalidArgumentException If validation fails.
	 * @throws RuntimeException If a database operation fails.
	 */
	public function save_student_choice( string $student_ufid, int $application_id, ?int $rank, TAAS_Match_Status $status, ?bool $has_ta_for_course = null ): void {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::rank( 'student_preference_rank', $rank );
		if ( ! in_array( $status, array( TAAS_Match_Status::Pending, TAAS_Match_Status::Withdrawn ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported student status transition.' );
		}
		$data = array( 'match_status' => $status->value );
		if ( null !== $has_ta_for_course ) {
			$data['has_ta_for_course'] = $has_ta_for_course ? 1 : 0;
		}
		if ( null !== $rank ) {
			$data['student_preference_rank'] = $rank;
			$data['has_taken_course']        = 1;
		}
		$this->update_where(
			$data,
			array(
				'student_ufid'   => $student_ufid,
				'application_id' => $application_id,
			)
		);
	}

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'applications';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'application_id';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Application::class;
	}

	/**
	 * Every application a student has submitted.
	 *
	 * @param string $student_ufid Student's UFID.
	 * @return TAAS_Application[]
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_student( string $student_ufid ): array {
		return $this->find_by( 'student_ufid', $student_ufid );
	}

	/**
	 * Every application submitted for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return TAAS_Application[]
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_course( int $course_id ): array {
		return $this->find_by( 'applied_course_id', $course_id );
	}

	/**
	 * Whether a course has any application, including withdrawn ones.
	 *
	 * @param int $course_id Course ID.
	 * @return bool
	 * @throws RuntimeException If the query fails.
	 */
	public function course_has_applications( int $course_id ): bool {
		global $wpdb;

		return ! empty(
			$this->select_rows(
				$wpdb->prepare( 'SELECT 1 FROM %i WHERE applied_course_id = %d LIMIT 1', $this->table(), $course_id )
			)
		);
	}

	/**
	 * Every application for a course that has not been withdrawn.
	 *
	 * These are the applications a professor sees and may rank.
	 *
	 * @param int $course_id Course ID.
	 * @return TAAS_Application[]
	 * @throws RuntimeException If the query fails.
	 */
	public function find_active_by_course( int $course_id ): array {
		return array_values(
			array_filter(
				$this->find_by_course( $course_id ),
				static fn( TAAS_Application $application ) => TAAS_Match_Status::Withdrawn !== $application->match_status
			)
		);
	}

	/**
	 * Non-withdrawn applicants with the details submitted for the course's term.
	 *
	 * Summary major/GPA come from the first (current or most recent) degree.
	 * Never substitute a current shared profile for a missing term snapshot.
	 * JSON paths below follow TAAS_Student_Submission's submitted_details contract.
	 *
	 * @param int $course_id Course ID.
	 * @return array<int, array{
	 *     application_id: int,
	 *     student_name: string,
	 *     major: ?string,
	 *     gpa: ?float,
	 *     enrollment_status: ?TAAS_Enrollment_Status,
	 *     has_taken_course: bool,
	 *     has_ta_for_course: bool
	 * }>
	 * @throws RuntimeException If the query fails or an applicant has no snapshot.
	 * @throws UnexpectedValueException If the stored enrollment status is invalid.
	 * @see TAAS_Student_Submission::__construct() Source snapshot field schema.
	 */
	public function applicants_for_course( int $course_id ): array {
		global $wpdb;

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT a.application_id, s.submission_id,'
				. " JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.student_name')) AS student_name,"
				. " JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.academic_background[0].major')) AS major,"
				. " NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.academic_background[0].gpa')), 'null') AS gpa,"
				. " JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.profile.enrollment_status')) AS enrollment_status,"
				. ' a.has_taken_course, a.has_ta_for_course'
				. ' FROM %i a'
				. ' INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' LEFT JOIN %i s ON s.student_ufid = a.student_ufid'
				. ' AND s.semester = c.course_semester AND s.year = c.course_year'
				. ' WHERE a.applied_course_id = %d AND a.match_status <> %d'
				. ' ORDER BY student_name, a.application_id',
				$this->table(),
				TAAS_Schema::table( 'courses' ),
				TAAS_Schema::table( 'student_submissions' ),
				$course_id,
				TAAS_Match_Status::Withdrawn->value
			)
		);

		foreach ( $rows as $row ) {
			if ( null === $row['submission_id'] ) {
				throw new RuntimeException( 'An applicant is missing their term submission snapshot.' );
			}
		}

		return array_map(
			static fn( array $r ) => array(
				'application_id'    => TAAS_Cast::int( $r['application_id'] ),
				'student_name'      => (string) $r['student_name'],
				'major'             => TAAS_Cast::string_or_null( $r['major'] ?? null ),
				'gpa'               => TAAS_Cast::float_or_null( $r['gpa'] ?? null ),
				'enrollment_status' => TAAS_Cast::enum_or_null( TAAS_Enrollment_Status::class, $r['enrollment_status'] ?? null ),
				'has_taken_course'  => TAAS_Cast::bool( $r['has_taken_course'] ?? 0 ),
				'has_ta_for_course' => TAAS_Cast::bool( $r['has_ta_for_course'] ?? 0 ),
			),
			$rows
		);
	}

	/**
	 * The list an admin chooses from when matching a course: each non-withdrawn
	 * application, ranked students first in rank order, then unranked students by name.
	 * Student name and available hours come from the course term's submission snapshot.
	 *
	 * @param int $course_id Course ID.
	 * @return array<int, array<string, mixed>> Rows in the shape the API sends.
	 * @throws RuntimeException If the query fails.
	 * @throws UnexpectedValueException If a stored match_status is not a known value.
	 */
	public function matching_applicants( int $course_id ): array {
		global $wpdb;

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT a.application_id, a.student_ufid,'
				. " JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.student_name')) AS student_name,"
				. ' a.professor_preference_rank, a.student_preference_rank,'
				. " NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.profile.available_hours')), 'null') AS available_hours,"
				. ' a.has_taken_course, a.has_ta_for_course, a.match_status, a.assigned_hours'
				. ' FROM %i a'
				. ' INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' INNER JOIN %i s ON s.student_ufid = a.student_ufid'
				. ' AND s.semester = c.course_semester AND s.year = c.course_year'
				. ' WHERE a.applied_course_id = %d AND a.match_status <> %d'
				. ' ORDER BY a.professor_preference_rank IS NULL, a.professor_preference_rank, student_name, a.application_id',
				$this->table(),
				TAAS_Schema::table( 'courses' ),
				TAAS_Schema::table( 'student_submissions' ),
				$course_id,
				TAAS_Match_Status::Withdrawn->value
			)
		);

		return array_map(
			static fn( array $r ) => array(
				'application_id'            => TAAS_Cast::int( $r['application_id'] ),
				'student_ufid'              => (string) $r['student_ufid'],
				'student_name'              => (string) $r['student_name'],
				'professor_preference_rank' => TAAS_Cast::int_or_null( $r['professor_preference_rank'] ?? null ),
				'student_preference_rank'   => TAAS_Cast::int_or_null( $r['student_preference_rank'] ?? null ),
				'available_hours'           => TAAS_Cast::int_or_null( $r['available_hours'] ?? null ),
				'has_taken_course'          => TAAS_Cast::bool( $r['has_taken_course'] ?? 0 ),
				'has_ta_for_course'         => TAAS_Cast::bool( $r['has_ta_for_course'] ?? 0 ),
				'match_status'              => TAAS_Cast::enum_or_null( TAAS_Match_Status::class, $r['match_status'] ?? null )?->label(),
				'assigned_hours'            => TAAS_Cast::int( $r['assigned_hours'] ?? 0 ),
			),
			$rows
		);
	}

	/**
	 * Every match on the given courses, by student name.
	 *
	 * @param int[] $course_ids Course IDs.
	 * @return array<int, array{application_id: int, course_id: int, student_ufid: string, student_name: string, assigned_hours: int}>
	 * @throws RuntimeException If the query fails.
	 */
	public function matches_for_courses( array $course_ids ): array {
		global $wpdb;

		if ( empty( $course_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

		$rows = $this->select_rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only %d placeholders are joined in; every value is bound.
			$wpdb->prepare(
				'SELECT a.application_id, a.applied_course_id, a.student_ufid, s.student_name, a.assigned_hours'
				. ' FROM %i a INNER JOIN %i s ON s.student_ufid = a.student_ufid'
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders are joined in.
				. " WHERE a.match_status = %d AND a.applied_course_id IN ({$placeholders})"
				. ' ORDER BY s.student_name, a.application_id',
				$this->table(),
				TAAS_Schema::table( 'students' ),
				TAAS_Match_Status::Matched->value,
				...$course_ids
			)
		);

		return array_map(
			static fn( array $r ) => array(
				'application_id' => TAAS_Cast::int( $r['application_id'] ),
				'course_id'      => TAAS_Cast::int( $r['applied_course_id'] ),
				'student_ufid'   => (string) $r['student_ufid'],
				'student_name'   => (string) $r['student_name'],
				'assigned_hours' => TAAS_Cast::int( $r['assigned_hours'] ),
			),
			$rows
		);
	}

	/**
	 * One page of the students with a non-withdrawn application in a term, by name.
	 * Student details come from that term's submission snapshot, so past terms show
	 * what the student submitted then.
	 *
	 * @param TAAS_Semester $semester     Semester to match.
	 * @param int           $year         Year to match.
	 * @param string|null   $student_ufid One student to match, or null for all.
	 * @param int           $limit        Page size, clamped to 1..MAX_LIMIT.
	 * @param int           $offset       Rows to skip, 0 or greater.
	 * @return array<int, array{student_ufid: string, student_name: string, student_email: string, available_hours: ?int}>
	 * @throws RuntimeException If the query fails.
	 */
	public function students_for_term( TAAS_Semester $semester, int $year, ?string $student_ufid = null, int $limit = self::DEFAULT_LIMIT, int $offset = 0 ): array {
		global $wpdb;

		$student_clause = null === $student_ufid ? '' : ' AND a.student_ufid = %s';
		$student_args   = null === $student_ufid ? array() : array( $student_ufid );

		$rows = $this->select_rows(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The clause's one placeholder is bound through $student_args.
			$wpdb->prepare(
				'SELECT DISTINCT s.student_ufid,'
				. " JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.student_name')) AS student_name,"
				. " JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.student_email')) AS student_email,"
				. " NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.submitted_details, '$.profile.available_hours')), 'null') AS available_hours"
				. ' FROM %i a'
				. ' INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' INNER JOIN %i s ON s.student_ufid = a.student_ufid'
				. ' AND s.semester = c.course_semester AND s.year = c.course_year'
				. ' WHERE c.course_semester = %d AND c.course_year = %d AND a.match_status <> %d'
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only the fixed clause above is joined in.
				. $student_clause
				. ' ORDER BY student_name, s.student_ufid LIMIT %d OFFSET %d',
				$this->table(),
				TAAS_Schema::table( 'courses' ),
				TAAS_Schema::table( 'student_submissions' ),
				$semester->value,
				$year,
				TAAS_Match_Status::Withdrawn->value,
				...$student_args,
				...array( max( 1, min( $limit, self::MAX_LIMIT ) ), max( 0, $offset ) )
			)
		);

		return array_map(
			static fn( array $r ) => array(
				'student_ufid'    => (string) $r['student_ufid'],
				'student_name'    => (string) $r['student_name'],
				'student_email'   => (string) $r['student_email'],
				'available_hours' => TAAS_Cast::int_or_null( $r['available_hours'] ?? null ),
			),
			$rows
		);
	}

	/**
	 * Count the students students_for_term() pages through.
	 *
	 * @param TAAS_Semester $semester Semester to match.
	 * @param int           $year     Year to match.
	 * @return int
	 * @throws RuntimeException If the query fails.
	 */
	public function count_students_for_term( TAAS_Semester $semester, int $year ): int {
		global $wpdb;

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT a.student_ufid) AS total'
				. ' FROM %i a INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' WHERE c.course_semester = %d AND c.course_year = %d AND a.match_status <> %d',
				$this->table(),
				TAAS_Schema::table( 'courses' ),
				$semester->value,
				$year,
				TAAS_Match_Status::Withdrawn->value
			)
		);

		return TAAS_Cast::int( $rows[0]['total'] ?? 0 );
	}

	/**
	 * Every match the given students have in a term, by course code.
	 *
	 * @param string[]      $student_ufids Students' UFIDs.
	 * @param TAAS_Semester $semester      Semester to match.
	 * @param int           $year          Year to match.
	 * @return array<int, array{application_id: int, student_ufid: string, course_id: int, course_code: string, assigned_hours: int}>
	 * @throws RuntimeException If the query fails.
	 */
	public function matches_for_students( array $student_ufids, TAAS_Semester $semester, int $year ): array {
		global $wpdb;

		if ( empty( $student_ufids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $student_ufids ), '%s' ) );

		$rows = $this->select_rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only %s placeholders are joined in; every value is bound.
			$wpdb->prepare(
				'SELECT a.application_id, a.student_ufid, c.course_id, c.course_code, a.assigned_hours'
				. ' FROM %i a INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' WHERE c.course_semester = %d AND c.course_year = %d AND a.match_status = %d'
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %s placeholders are joined in.
				. " AND a.student_ufid IN ({$placeholders})"
				. ' ORDER BY c.course_code, a.application_id',
				$this->table(),
				TAAS_Schema::table( 'courses' ),
				$semester->value,
				$year,
				TAAS_Match_Status::Matched->value,
				...$student_ufids
			)
		);

		return array_map(
			static fn( array $r ) => array(
				'application_id' => TAAS_Cast::int( $r['application_id'] ),
				'student_ufid'   => (string) $r['student_ufid'],
				'course_id'      => TAAS_Cast::int( $r['course_id'] ),
				'course_code'    => (string) $r['course_code'],
				'assigned_hours' => TAAS_Cast::int( $r['assigned_hours'] ),
			),
			$rows
		);
	}

	/**
	 * Count a term's matches and pending applications, and add up its assigned hours.
	 *
	 * @param TAAS_Semester $semester Semester to match.
	 * @param int           $year     Year to match.
	 * @return array{matches_made: int, applications_pending: int, hours_assigned: int}
	 * @throws RuntimeException If the query fails.
	 */
	public function progress_for_term( TAAS_Semester $semester, int $year ): array {
		global $wpdb;

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(a.match_status = %d), 0) AS matches_made,'
				. ' COALESCE(SUM(a.match_status = %d), 0) AS applications_pending,'
				. ' COALESCE(SUM(CASE WHEN a.match_status = %d THEN a.assigned_hours ELSE 0 END), 0) AS hours_assigned'
				. ' FROM %i a INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' WHERE c.course_semester = %d AND c.course_year = %d',
				TAAS_Match_Status::Matched->value,
				TAAS_Match_Status::Pending->value,
				TAAS_Match_Status::Matched->value,
				$this->table(),
				TAAS_Schema::table( 'courses' ),
				$semester->value,
				$year
			)
		);

		return array(
			'matches_made'         => TAAS_Cast::int( $rows[0]['matches_made'] ?? 0 ),
			'applications_pending' => TAAS_Cast::int( $rows[0]['applications_pending'] ?? 0 ),
			'hours_assigned'       => TAAS_Cast::int( $rows[0]['hours_assigned'] ?? 0 ),
		);
	}

	/**
	 * Set an application's match status and assigned hours, leaving every other column alone.
	 *
	 * A withdrawn application is left as it is. The check is part of the UPDATE, so a
	 * withdrawal that lands just before this call cannot be overwritten.
	 *
	 * @param int               $application_id Application id.
	 * @param TAAS_Match_Status $status         New status.
	 * @param int               $assigned_hours New assigned hours.
	 * @return int Rows changed; 0 when the application is withdrawn, missing or already held these values.
	 * @throws RuntimeException If the update fails.
	 */
	public function set_match( int $application_id, TAAS_Match_Status $status, int $assigned_hours ): int {
		global $wpdb;

		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET match_status = %d, assigned_hours = %d'
				. ' WHERE application_id = %d AND match_status <> %d',
				$this->table(),
				$status->value,
				$assigned_hours,
				$application_id,
				TAAS_Match_Status::Withdrawn->value
			)
		);

		if ( false === $changed ) {
			throw new RuntimeException( 'Update of applications failed: ' . esc_html( $wpdb->last_error ) );
		}

		return (int) $changed;
	}

	/**
	 * Reject every pending application in a term.
	 *
	 * One statement, so it applies to all of them or none.
	 *
	 * @param TAAS_Semester $semester Semester to match.
	 * @param int           $year     Year to match.
	 * @return int Applications rejected.
	 * @throws RuntimeException If the update fails.
	 */
	public function reject_pending_for_term( TAAS_Semester $semester, int $year ): int {
		global $wpdb;

		$rejected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i a INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' SET a.match_status = %d, a.assigned_hours = 0'
				. ' WHERE c.course_semester = %d AND c.course_year = %d AND a.match_status = %d',
				$this->table(),
				TAAS_Schema::table( 'courses' ),
				TAAS_Match_Status::Rejected->value,
				$semester->value,
				$year,
				TAAS_Match_Status::Pending->value
			)
		);

		if ( false === $rejected ) {
			throw new RuntimeException( 'Finalize of applications failed: ' . esc_html( $wpdb->last_error ) );
		}

		return (int) $rejected;
	}

	/**
	 * Replace a professor's whole ranking for one course.
	 *
	 * Every application for the course is first reset to unranked (NULL), then
	 * the application at index i gets rank i + 1. An application left out of the
	 * list therefore ends up unranked, which the matcher treats as equal last.
	 * Validate the ranking first (TAAS_Ranking::parse()) and call this inside
	 * TAAS_Transaction::run() so a failure halfway cannot leave a partial ranking.
	 *
	 * @param int   $course_id   Course ID.
	 * @param int[] $ordered_ids Application ids, best first; empty clears the ranking.
	 * @return void
	 * @throws RuntimeException If an update fails.
	 */
	public function set_professor_ranks( int $course_id, array $ordered_ids ): void {
		$this->update_where(
			array( 'professor_preference_rank' => null ),
			array( 'applied_course_id' => $course_id )
		);

		foreach ( array_values( $ordered_ids ) as $index => $application_id ) {
			$this->update_where(
				array( 'professor_preference_rank' => $index + 1 ),
				array(
					'application_id'    => (int) $application_id,
					'applied_course_id' => $course_id,
				)
			);
		}
	}
}
