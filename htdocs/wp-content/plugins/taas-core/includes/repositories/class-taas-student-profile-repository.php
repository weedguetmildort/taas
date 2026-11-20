<?php
/**
 * Repository for the student_profiles table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for student profiles, keyed by student_ufid (one per student).
 */
final class TAAS_Student_Profile_Repository extends TAAS_Repository {

	/**
	 * Change only the advisor, preserving every other shared profile field.
	 *
	 * @param string      $student_ufid Verified submission owner.
	 * @param string      $advisor_name Validated advisor name.
	 * @param string|null $advisor_email Validated advisor email.
	 * @throws RuntimeException If persistence fails.
	 */
	public function save_advisor_name( string $student_ufid, string $advisor_name, ?string $advisor_email = null ): void {
		global $wpdb;
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		$sql = $wpdb->prepare(
			'INSERT INTO %i (student_ufid, advisor_name, advisor_email) VALUES (%s, %s, NULLIF(%s, \'\')) ON DUPLICATE KEY UPDATE advisor_name = %s, advisor_email = NULLIF(%s, \'\')',
			$this->table(),
			$student_ufid,
			$advisor_name,
			$advisor_email ?? '',
			$advisor_name,
			$advisor_email ?? ''
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( 'Saving advisor name failed.' );
		}
	}

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'student_profiles';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'student_ufid';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Student_Profile::class;
	}

	/**
	 * Profiles of every student with a Pending or Matched application in a term, by UFID.
	 *
	 * The same applicants TAAS_Application_Repository::find_for_matching() returns applications for.
	 *
	 * @param TAAS_Semester $semester Semester to match.
	 * @param int           $year     Year to match.
	 * @return TAAS_Student_Profile[]
	 * @throws RuntimeException If the query fails.
	 */
	public function find_for_matching( TAAS_Semester $semester, int $year ): array {
		global $wpdb;

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT DISTINCT p.* FROM %i p'
				. ' INNER JOIN %i a ON a.student_ufid = p.student_ufid'
				. ' INNER JOIN %i c ON c.course_id = a.applied_course_id'
				. ' WHERE c.course_semester = %d AND c.course_year = %d AND a.match_status IN (%d, %d)'
				. ' ORDER BY p.student_ufid',
				$this->table(),
				TAAS_Schema::table( 'applications' ),
				TAAS_Schema::table( 'courses' ),
				$semester->value,
				$year,
				TAAS_Match_Status::Pending->value,
				TAAS_Match_Status::Matched->value
			)
		);

		return array_map( array( TAAS_Student_Profile::class, 'from_row' ), $rows );
	}
}
