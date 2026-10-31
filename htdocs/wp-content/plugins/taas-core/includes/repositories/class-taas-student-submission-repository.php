<?php
/**
 * Storage for submitted student details, grouped by student and term.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

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
	 * @return array Submission IDs and academic terms, latest year first.
	 */
	public function terms_for_student( string $student_ufid ): array {
		global $wpdb;
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
	 * Create a term snapshot or replace its details in one atomic statement.
	 *
	 * The unique student/term key prevents duplicate snapshots on retries.
	 * When saving course applications too, wrap all writes in TAAS_Transaction.
	 * This does not by itself authorize replacing an existing initial submission;
	 * that policy belongs to the caller. No previous revisions are retained.
	 *
	 * @param string        $student_ufid Verified owner identifier.
	 * @param TAAS_Semester $semester     Application semester.
	 * @param int           $year         Application year.
	 * @param array         $details      Validated submitted details.
	 * @return int Saved submission ID, unchanged on replacement.
	 * @throws RuntimeException If the database write fails.
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
