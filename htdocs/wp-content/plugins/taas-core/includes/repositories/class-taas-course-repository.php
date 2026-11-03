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
}
