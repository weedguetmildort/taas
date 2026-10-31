<?php
/**
 * Repository for the applications table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for applications, keyed by application_id.
 */
final class TAAS_Application_Repository extends TAAS_Repository {

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
	 * @param string $course_id Course section number.
	 * @return TAAS_Application[]
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_course( string $course_id ): array {
		return $this->find_by( 'applied_course_id', $course_id );
	}

	/**
	 * Every application for a course that has not been withdrawn.
	 *
	 * These are the applications a professor sees and may rank.
	 *
	 * @param string $course_id Course section number.
	 * @return TAAS_Application[]
	 * @throws RuntimeException If the query fails.
	 */
	public function find_active_by_course( string $course_id ): array {
		return array_values(
			array_filter(
				$this->find_by_course( $course_id ),
				static fn( TAAS_Application $application ) => TAAS_Match_Status::Withdrawn !== $application->match_status
			)
		);
	}

	/**
	 * The applicant list for a course: each non-withdrawn application joined to
	 * the student's name and current profile, ordered by student name.
	 *
	 * The profile is a LEFT JOIN, so a student without one still appears with
	 * null major, gpa and enrollment_status.
	 *
	 * @param string $course_id Course section number.
	 * @return array<int, array{
	 *     application_id: int,
	 *     student_name: string,
	 *     major: ?string,
	 *     gpa: ?float,
	 *     enrollment_status: ?TAAS_Enrollment_Status,
	 *     has_taken_course: bool,
	 *     has_ta_for_course: bool
	 * }>
	 * @throws RuntimeException If the query fails.
	 * @throws UnexpectedValueException If a stored enrollment_status is not a known value.
	 */
	public function applicants_for_course( string $course_id ): array {
		global $wpdb;

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT a.application_id, s.student_name, p.major, p.gpa, p.enrollment_status,'
				. ' a.has_taken_course, a.has_ta_for_course'
				. ' FROM %i a'
				. ' INNER JOIN %i s ON s.student_ufid = a.student_ufid'
				. ' LEFT JOIN %i p ON p.student_ufid = a.student_ufid'
				. ' WHERE a.applied_course_id = %s AND a.match_status <> %d'
				. ' ORDER BY s.student_name, a.application_id',
				$this->table(),
				TAAS_Schema::table( 'students' ),
				TAAS_Schema::table( 'student_profiles' ),
				$course_id,
				TAAS_Match_Status::Withdrawn->value
			)
		);

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
	 * Replace a professor's whole ranking for one course.
	 *
	 * Every application for the course is first reset to unranked (NULL), then
	 * the application at index i gets rank i + 1. An application left out of the
	 * list therefore ends up unranked, which the matcher treats as equal last.
	 * Validate the ranking first (TAAS_Ranking::parse()) and call this inside
	 * TAAS_Transaction::run() so a failure halfway cannot leave a partial ranking.
	 *
	 * @param string $course_id   Course section number.
	 * @param int[]  $ordered_ids Application ids, best first; empty clears the ranking.
	 * @return void
	 * @throws RuntimeException If an update fails.
	 */
	public function set_professor_ranks( string $course_id, array $ordered_ids ): void {
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
