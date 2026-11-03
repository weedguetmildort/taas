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
