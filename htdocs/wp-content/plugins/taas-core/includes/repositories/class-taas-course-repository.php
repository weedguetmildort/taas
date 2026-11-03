<?php
/**
 * Repository for the courses table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for courses, keyed by course_id.
 */
final class TAAS_Course_Repository extends TAAS_Repository {

	/**
	 * Student course choices with instructor display names in one term.
	 *
	 * @param TAAS_Semester $semester Configured semester.
	 * @param int           $year     Configured year.
	 * @return list<array{
	 *   course_id: string, course_code: string, course_name: string, instructor_name: string
	 * }> Display fields only, ordered by course code and ID.
	 * @throws InvalidArgumentException If the year is invalid.
	 * @throws RuntimeException If the query fails.
	 */
	public function application_choices( TAAS_Semester $semester, int $year ): array {
		global $wpdb;
		TAAS_Guard::year( 'year', $year );
		return $this->select_rows(
			$wpdb->prepare(
				'SELECT c.course_id, c.course_code, c.course_name, p.professor_name AS instructor_name'
				. ' FROM %i c INNER JOIN %i p ON p.professor_ufid = c.professor_ufid'
				. ' WHERE c.course_semester = %d AND c.course_year = %d ORDER BY c.course_code, c.course_id',
				$this->table(),
				TAAS_Schema::table( 'professors' ),
				$semester->value,
				$year
			)
		);
	}

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'courses';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'course_id';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Course::class;
	}

	/**
	 * Every course a professor teaches.
	 *
	 * @param string $professor_ufid Professor's UFID.
	 * @return TAAS_Course[]
	 * @throws InvalidArgumentException If the UFID is invalid.
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_professor( string $professor_ufid ): array {
		TAAS_Guard::ufid( 'professor_ufid', $professor_ufid );
		return $this->find_by( 'professor_ufid', $professor_ufid );
	}

	/**
	 * Every course offered in one term, e.g. Fall 2026.
	 *
	 * @param TAAS_Semester $semester Semester to match.
	 * @param int           $year     Year to match.
	 * @return TAAS_Course[]
	 * @throws InvalidArgumentException If the year is invalid.
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_term( TAAS_Semester $semester, int $year ): array {
		TAAS_Guard::year( 'year', $year );
		return $this->find_where(
			array(
				'course_semester' => $semester->value,
				'course_year'     => $year,
			)
		);
	}

	/**
	 * Every course one professor teaches in one term, e.g. their Summer B 2026 courses.
	 *
	 * @param string        $professor_ufid Professor's UFID.
	 * @param TAAS_Semester $semester       Semester to match.
	 * @param int           $year           Year to match.
	 * @return TAAS_Course[]
	 * @throws InvalidArgumentException If the UFID or year is invalid.
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_professor_and_term( string $professor_ufid, TAAS_Semester $semester, int $year ): array {
		TAAS_Guard::ufid( 'professor_ufid', $professor_ufid );
		TAAS_Guard::year( 'year', $year );
		return $this->find_where(
			array(
				'professor_ufid'  => $professor_ufid,
				'course_semester' => $semester->value,
				'course_year'     => $year,
			)
		);
	}

	/**
	 * The course with this term, year, code and name, if there is one.
	 *
	 * @param TAAS_Semester $semester Semester to match.
	 * @param int           $year     Year to match.
	 * @param string        $code     Course code to match.
	 * @param string        $name     Course name to match.
	 * @return TAAS_Course|null
	 * @throws RuntimeException If the query fails.
	 */
	public function find_offering( TAAS_Semester $semester, int $year, string $code, string $name ): ?TAAS_Course {
		$matches = $this->find_where(
			array(
				'course_semester' => $semester->value,
				'course_year'     => $year,
				'course_code'     => $code,
				'course_name'     => $name,
			)
		);

		return $matches[0] ?? null;
	}

	/**
	 * One course joined to its professor.
	 *
	 * @param int $course_id Course ID.
	 * @return array{course: TAAS_Course, professor_name: string, professor_email: string}|null
	 * @throws RuntimeException If the query fails.
	 */
	public function find_with_professor( int $course_id ): ?array {
		global $wpdb;

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT c.*, p.professor_name, p.professor_email'
				. ' FROM %i c INNER JOIN %i p ON p.professor_ufid = c.professor_ufid'
				. ' WHERE c.course_id = %d',
				$this->table(),
				TAAS_Schema::table( 'professors' ),
				$course_id
			)
		);

		return empty( $rows ) ? null : self::with_professor( $rows[0] );
	}

	/**
	 * One page of a term's courses joined to their professors, ordered by code and name.
	 *
	 * @param TAAS_Semester $semester       Semester to match.
	 * @param int           $year           Year to match.
	 * @param string|null   $course_code    Exact code to match, or null for any.
	 * @param string|null   $professor_ufid Professor to match, or null for any.
	 * @param int           $limit          Page size, clamped to 1..MAX_LIMIT.
	 * @param int           $offset         Rows to skip, 0 or greater.
	 * @return array<int, array{course: TAAS_Course, professor_name: string, professor_email: string}>
	 * @throws RuntimeException If the query fails.
	 */
	public function search_with_professor( TAAS_Semester $semester, int $year, ?string $course_code = null, ?string $professor_ufid = null, int $limit = self::DEFAULT_LIMIT, int $offset = 0 ): array {
		global $wpdb;

		list( $where, $args ) = self::search_where( $semester, $year, $course_code, $professor_ufid );

		$rows = $this->select_rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only fixed fragments are joined in; every value is bound.
			$wpdb->prepare(
				'SELECT c.*, p.professor_name, p.professor_email'
				. ' FROM %i c INNER JOIN %i p ON p.professor_ufid = c.professor_ufid'
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed fragments are joined in.
				. ' WHERE ' . $where
				. ' ORDER BY c.course_code, c.course_name, c.course_id LIMIT %d OFFSET %d',
				$this->table(),
				TAAS_Schema::table( 'professors' ),
				...$args,
				...array( max( 1, min( $limit, self::MAX_LIMIT ) ), max( 0, $offset ) )
			)
		);

		return array_map( array( self::class, 'with_professor' ), $rows );
	}

	/**
	 * Count the courses search_with_professor() pages through.
	 *
	 * @param TAAS_Semester $semester       Semester to match.
	 * @param int           $year           Year to match.
	 * @param string|null   $course_code    Exact code to match, or null for any.
	 * @param string|null   $professor_ufid Professor to match, or null for any.
	 * @return int
	 * @throws RuntimeException If the query fails.
	 */
	public function count_search( TAAS_Semester $semester, int $year, ?string $course_code = null, ?string $professor_ufid = null ): int {
		global $wpdb;

		list( $where, $args ) = self::search_where( $semester, $year, $course_code, $professor_ufid );

		$rows = $this->select_rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only fixed fragments are joined in; every value is bound.
			$wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i c WHERE ' . $where, $this->table(), ...$args )
		);

		return TAAS_Cast::int( $rows[0]['total'] ?? 0 );
	}

	/**
	 * Add up a term's weekly_hours.
	 *
	 * @param TAAS_Semester $semester Semester to match.
	 * @param int           $year     Year to match.
	 * @return int
	 * @throws RuntimeException If the query fails.
	 */
	public function total_weekly_hours( TAAS_Semester $semester, int $year ): int {
		global $wpdb;

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(weekly_hours), 0) AS total FROM %i WHERE course_semester = %d AND course_year = %d',
				$this->table(),
				$semester->value,
				$year
			)
		);

		return TAAS_Cast::int( $rows[0]['total'] ?? 0 );
	}

	/**
	 * Save an edited course, leaving course_notes alone.
	 *
	 * The notes belong to the professor, who may have saved new ones since the
	 * course was read.
	 *
	 * @param int         $course_id Course ID.
	 * @param TAAS_Course $course    Course holding the new values.
	 * @return void
	 * @throws RuntimeException If the update fails.
	 */
	public function update_details( int $course_id, TAAS_Course $course ): void {
		$data = $course->to_row();
		unset( $data['course_id'], $data['course_notes'] );

		$this->update_where( $data, array( 'course_id' => $course_id ) );
	}

	/**
	 * Replace a course's professor notes, leaving every other column alone.
	 *
	 * @param int         $course_id Course ID.
	 * @param string|null $notes     New notes, or null to clear them.
	 * @return bool True if the notes changed.
	 * @throws RuntimeException If the update fails.
	 */
	public function update_notes( int $course_id, ?string $notes ): bool {
		return $this->update_where(
			array( 'course_notes' => $notes ),
			array( 'course_id' => $course_id )
		) > 0;
	}

	/**
	 * Build the WHERE clause and bound values for a course search.
	 *
	 * @param TAAS_Semester $semester       Semester to match.
	 * @param int           $year           Year to match.
	 * @param string|null   $course_code    Exact code to match, or null for any.
	 * @param string|null   $professor_ufid Professor to match, or null for any.
	 * @return array{0: string, 1: array<int, string|int>}
	 */
	private static function search_where( TAAS_Semester $semester, int $year, ?string $course_code, ?string $professor_ufid ): array {
		$clauses = array( 'c.course_semester = %d', 'c.course_year = %d' );
		$args    = array( $semester->value, $year );

		if ( null !== $course_code ) {
			$clauses[] = 'c.course_code = %s';
			$args[]    = $course_code;
		}

		if ( null !== $professor_ufid ) {
			$clauses[] = 'c.professor_ufid = %s';
			$args[]    = $professor_ufid;
		}

		return array( implode( ' AND ', $clauses ), $args );
	}

	/**
	 * Split a joined row into the course and its professor's details.
	 *
	 * @param array $row Raw row with the courses columns, professor_name and professor_email.
	 * @return array{course: TAAS_Course, professor_name: string, professor_email: string}
	 */
	private static function with_professor( array $row ): array {
		return array(
			'course'          => TAAS_Course::from_row( $row ),
			'professor_name'  => (string) $row['professor_name'],
			'professor_email' => (string) $row['professor_email'],
		);
	}
}
