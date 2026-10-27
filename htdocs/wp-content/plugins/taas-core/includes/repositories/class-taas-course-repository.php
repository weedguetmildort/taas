<?php
/**
 * Repository for the courses table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for courses, keyed by course_id (the section number).
 */
final class TAAS_Course_Repository extends TAAS_Repository {

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
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_professor( string $professor_ufid ): array {
		return $this->find_by( 'professor_ufid', $professor_ufid );
	}

	/**
	 * Every course offered in one term, e.g. Fall 2026.
	 *
	 * @param TAAS_Semester $semester Semester to match.
	 * @param int           $year     Year to match.
	 * @return TAAS_Course[]
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_term( TAAS_Semester $semester, int $year ): array {
		return $this->find_where(
			array(
				'course_semester' => $semester->value,
				'course_year'     => $year,
			)
		);
	}
}
