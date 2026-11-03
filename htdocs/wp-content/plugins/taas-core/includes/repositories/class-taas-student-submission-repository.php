<?php
/**
 * Storage for submitted student details, grouped by student and term.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- Public contracts document exceptions propagated by guards and persistence helpers too.

/**
 * Persistence only: callers enforce authentication, ownership and deadlines.
 */
final class TAAS_Student_Submission_Repository extends TAAS_Repository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'student_submissions';
	}

	/**
	 * Generated primary key.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'submission_id';
	}

	/**
	 * Typed row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Student_Submission::class;
	}

	/**
	 * List the owner's saved terms without loading every snapshot's JSON.
	 *
	 * @param string $student_ufid Verified owner.
	 * @return list<array{submission_id: string, semester: string, year: string}>
	 *         Raw database values, latest year first, then newest submission ID.
	 */
	public function terms_for_student( string $student_ufid ): array {
		global $wpdb;
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		return $this->select_rows(
			$wpdb->prepare(
				'SELECT submission_id, semester, year FROM %i WHERE student_ufid = %s ORDER BY year DESC, submission_id DESC',
				$this->table(),
				$student_ufid
			)
		);
	}

	/**
	 * Latest created term snapshot, used to prefill values without shared columns.
	 *
	 * @param string $student_ufid Verified owner.
	 * @return TAAS_Student_Submission|null
	 */
	public function latest_for_student( string $student_ufid ): ?TAAS_Student_Submission {
		global $wpdb;
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		$rows = $this->select_rows(
			$wpdb->prepare( 'SELECT * FROM %i WHERE student_ufid = %s ORDER BY submission_id DESC LIMIT 1', $this->table(), $student_ufid )
		);
		return $rows ? TAAS_Student_Submission::from_row( $rows[0] ) : null;
	}

	/**
	 * Retrieve only the requested student's saved term snapshot.
	 *
	 * @param string        $student_ufid Verified owner identifier.
	 * @param TAAS_Semester $semester     Application semester.
	 * @param int           $year         Application year.
	 * @return TAAS_Student_Submission|null Null if no submission exists.
	 */
	public function find_by_student_and_term( string $student_ufid, TAAS_Semester $semester, int $year ): ?TAAS_Student_Submission {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::year( 'year', $year );
		$rows = $this->find_where(
			array(
				'student_ufid' => $student_ufid,
				'semester'     => $semester->value,
				'year'         => $year,
			)
		);
		return $rows[0] ?? null;
	}

	/**
	 * Read a snapshot and its course rows in one statement so they cannot mix saves.
	 * A left join preserves applications whose preferences have all been withdrawn
	 * or which have no course rows. Row order matches the write service's revision.
	 *
	 * @param string        $student_ufid Verified owner identifier.
	 * @param TAAS_Semester $semester     Application semester.
	 * @param int           $year         Application year.
	 * @return array{
	 *   snapshot: TAAS_Student_Submission,
	 *   choices: list<array{
	 *     application_id: string, student_ufid: string, applied_course_id: string,
	 *     has_taken_course: string, has_ta_for_course: string,
	 *     student_statement: string|null, student_preference_rank: string|null,
	 *     professor_preference_rank: string|null, match_status: string, assigned_hours: string
	 *   }>
	 * }|null Null when no snapshot exists; choices contain raw database values.
	 */
	public function find_application_for_term( string $student_ufid, TAAS_Semester $semester, int $year ): ?array {
		global $wpdb;
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::year( 'year', $year );
		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT s.submission_id, s.student_ufid AS snapshot_owner, s.semester, s.year, s.submitted_details, a.*'
				. ' FROM %i s LEFT JOIN (%i a INNER JOIN %i c ON c.course_id = a.applied_course_id)'
				. ' ON a.student_ufid = s.student_ufid AND c.course_semester = s.semester AND c.course_year = s.year'
				. ' WHERE s.student_ufid = %s AND s.semester = %d AND s.year = %d'
				. ' ORDER BY a.student_preference_rank, a.application_id',
				$this->table(),
				TAAS_Schema::table( 'applications' ),
				TAAS_Schema::table( 'courses' ),
				$student_ufid,
				$semester->value,
				$year
			)
		);
		if ( ! $rows ) {
			return null;
		}
		$first_row = $rows[0];
		$snapshot_data = array(
			'submission_id'     => $first_row['submission_id'],
			'student_ufid'      => $first_row['snapshot_owner'],
			'semester'          => $first_row['semester'],
			'year'              => $first_row['year'],
			'submitted_details' => $first_row['submitted_details'],
		);
		$snapshot_columns = array_flip( array( 'submission_id', 'snapshot_owner', 'semester', 'year', 'submitted_details' ) );
		$choices = array();
		foreach ( $rows as $row ) {
			if ( null !== $row['application_id'] ) {
				// Preserve database column order because revision tokens include these rows.
				$course_row = array_diff_key( $row, $snapshot_columns );
				$choices[] = $course_row;
			}
		}
		return array(
			'snapshot' => TAAS_Student_Submission::from_row( $snapshot_data ),
			'choices'  => $choices,
		);
	}

	/**
	 * Create a term snapshot or replace its details in one atomic statement.
	 *
	 * The unique student/term key prevents duplicate snapshots on retries.
	 * When saving course applications too, wrap all writes in TAAS_Transaction.
	 * This does not by itself authorize replacing an existing initial submission;
	 * that policy belongs to the caller. No previous revisions are retained.
	 *
	 * $details is the normalized input['details'] object: student_name,
	 * student_email, profile and ordered academic_background entries. It is encoded
	 * into submitted_details JSON without adding term, owner or course preferences.
	 * Nested validation must already have run; this repository does not repeat it.
	 *
	 * @param string        $student_ufid Verified owner identifier.
	 * @param TAAS_Semester $semester     Application semester.
	 * @param int           $year         Application year.
	 * @param array         $details      Validated submitted details.
	 * @return int Saved submission ID, unchanged on replacement.
	 * @throws RuntimeException If database persistence fails.
	 * @throws JsonException If submitted details cannot be encoded.
	 * @see TAAS_Student_Submission::__construct() Full submitted_details field schema.
	 * @see TAAS_Student_Application_Input::parse() Request validation and normalization.
	 */
	public function save_for_term( string $student_ufid, TAAS_Semester $semester, int $year, array $details ): int {
		global $wpdb;
		$entity = new TAAS_Student_Submission( null, $student_ufid, $semester, $year, $details );
		$data   = $entity->to_row();
		// A single upsert avoids a read/insert race. Only this term's JSON changes.
		$sql = $wpdb->prepare(
			'INSERT INTO %i (student_ufid, semester, year, submitted_details) VALUES (%s, %d, %d, %s)'
			. ' ON DUPLICATE KEY UPDATE submission_id = LAST_INSERT_ID(submission_id), submitted_details = %s',
			$this->table(),
			$student_ufid,
			$semester->value,
			$year,
			$data['submitted_details'],
			$data['submitted_details']
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above; upsert is atomic.
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( 'Saving student submission failed: ' . esc_html( $wpdb->last_error ) );
		}
		return (int) $wpdb->insert_id;
	}
}
