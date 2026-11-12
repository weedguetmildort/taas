<?php
/**
 * Persistence for HR decisions and review progress.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/** Store and read the latest decision per submission. */
final class TAAS_HR_Review_Repository extends TAAS_Repository {

	/** Review table. */
	protected function bare_table(): string {
		return 'hr_reviews';
	}

	/** A review shares its submission's primary key. */
	protected function primary_key(): string {
		return 'submission_id';
	}

	/** Typed review row. */
	protected function row_class(): string {
		return TAAS_HR_Review::class;
	}

	/**
	 * Read the term's submissions and reviews in one database snapshot.
	 *
	 * @param TAAS_Application_Cycle $cycle Current cycle.
	 * @return list<array{submission: TAAS_Student_Submission, decision: ?string, version: int}>
	 */
	public function applicants_for_cycle( TAAS_Application_Cycle $cycle ): array {
		global $wpdb;
		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT s.*, r.decision, r.version FROM %i s LEFT JOIN %i r ON r.submission_id = s.submission_id'
				. ' WHERE s.semester = %d AND s.year = %d ORDER BY s.submission_id',
				TAAS_Schema::table( 'student_submissions' ),
				$this->table(),
				$cycle->cycle_semester->value,
				$cycle->cycle_year
			)
		);
		return array_map(
			static fn( array $row ) => array(
				'submission' => TAAS_Student_Submission::from_row( $row ),
				'decision'   => $row['decision'],
				'version'    => (int) $row['version'],
			),
			$rows
		);
	}

	/**
	 * Lock the parent submission even before its first review exists.
	 *
	 * @param int                    $id    Submission ID.
	 * @param TAAS_Application_Cycle $cycle Current cycle.
	 * @return bool True if the submission belongs to the cycle.
	 */
	public function lock_submission( int $id, TAAS_Application_Cycle $cycle ): bool {
		global $wpdb;
		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT submission_id FROM %i WHERE submission_id = %d AND semester = %d AND year = %d FOR UPDATE',
				TAAS_Schema::table( 'student_submissions' ),
				$id,
				$cycle->cycle_semester->value,
				$cycle->cycle_year
			)
		);
		return ! empty( $rows );
	}

	/**
	 * Read the latest decision after locking its parent submission.
	 *
	 * @param int $id Submission ID.
	 * @return array{decision: ?string, version: int}
	 */
	public function current_review( int $id ): array {
		global $wpdb;
		$rows = $this->select_rows(
			$wpdb->prepare( 'SELECT decision, version FROM %i WHERE submission_id = %d', $this->table(), $id )
		);
		return array(
			'decision' => $rows[0]['decision'] ?? null,
			'version'  => (int) ( $rows[0]['version'] ?? 0 ),
		);
	}

	/**
	 * Record the first decision or revise an existing decision under its expected version.
	 *
	 * @param int      $id          Submission ID, already locked and term-checked.
	 * @param string   $decision    Approve or reject.
	 * @param int      $version     Expected version.
	 * @param int|null $reviewer_id WordPress user ID, or null for a local mock user.
	 * @throws RuntimeException If the expected row was not updated.
	 */
	public function save_review( int $id, string $decision, int $version, ?int $reviewer_id ): void {
		global $wpdb;
		$review = new TAAS_HR_Review( $id, $decision, $version + 1, $reviewer_id, current_time( 'mysql', true ) );
		if ( 0 === $version ) {
			$this->insert( $review );
			return;
		}

		$data = $review->to_row();
		unset( $data['submission_id'] );
		$updated = $wpdb->update(
			$this->table(),
			$data,
			array(
				'submission_id' => $id,
				'version'       => $version,
			),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d', '%d' )
		);
		if ( 1 !== $updated ) {
			throw new RuntimeException( 'The HR decision could not be updated.' );
		}
	}

	/**
	 * Count reviews for a cycle, including this transaction's changes.
	 *
	 * @param TAAS_Application_Cycle $cycle Current cycle.
	 * @return array{total: int, reviewed: int, approved: int}
	 */
	public function progress( TAAS_Application_Cycle $cycle ): array {
		global $wpdb;
		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, COUNT(r.submission_id) AS reviewed, COUNT(CASE WHEN r.decision = %s THEN 1 END) AS approved'
				. ' FROM %i s LEFT JOIN %i r ON r.submission_id = s.submission_id WHERE s.semester = %d AND s.year = %d',
				'approve',
				TAAS_Schema::table( 'student_submissions' ),
				$this->table(),
				$cycle->cycle_semester->value,
				$cycle->cycle_year
			)
		);
		return array_map( 'intval', $rows[0] );
	}
}
