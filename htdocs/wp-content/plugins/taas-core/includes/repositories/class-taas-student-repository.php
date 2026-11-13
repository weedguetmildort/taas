<?php
/**
 * Repository for the students table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for students, keyed by student_ufid.
 */
final class TAAS_Student_Repository extends TAAS_Repository {

	/**
	 * Serialize a withdrawal with other saves by this student.
	 *
	 * @param string $student_ufid Verified owner.
	 */
	public function lock( string $student_ufid ): void {
		global $wpdb;
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		$this->select_rows( $wpdb->prepare( 'SELECT student_ufid FROM %i WHERE student_ufid = %s FOR UPDATE', $this->table(), $student_ufid ) );
	}

	/**
	 * Save identity and lock this student for the surrounding transaction.
	 *
	 * Initial submissions use the lock to serialize duplicate requests for a
	 * student. Only verified account identity may supply student_ufid.
	 * Call inside a transaction to retain the lock until commit or rollback;
	 * without one, the lock is released when this statement completes.
	 *
	 * @param TAAS_Student $student Validated identity.
	 * @return void
	 * @throws RuntimeException If the database write fails.
	 */
	public function save_identity( TAAS_Student $student ): void {
		global $wpdb;
		$sql = $wpdb->prepare(
			'INSERT INTO %i (student_ufid, student_name, student_email) VALUES (%s, %s, %s)'
			. ' ON DUPLICATE KEY UPDATE student_name = %s, student_email = %s',
			$this->table(),
			$student->student_ufid,
			$student->student_name,
			$student->student_email,
			$student->student_name,
			$student->student_email
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( 'Saving student identity failed: ' . esc_html( $wpdb->last_error ) );
		}
	}

	/**
	 * Add a student who has signed in but not applied, leaving an existing row unchanged.
	 *
	 * Sign-in only seeds the row. Name and email stay whatever the student last
	 * submitted, so a later sign-in never replaces a name they corrected.
	 *
	 * @param TAAS_Student $student Identity from the verified account.
	 * @return bool Whether a new row was added.
	 * @throws RuntimeException If the database write fails.
	 */
	public function add_if_missing( TAAS_Student $student ): bool {
		global $wpdb;
		// Unlike INSERT IGNORE, a no-op update on the key still reports every other error.
		$sql = $wpdb->prepare(
			'INSERT INTO %i (student_ufid, student_name, student_email) VALUES (%s, %s, %s)'
			. ' ON DUPLICATE KEY UPDATE student_ufid = student_ufid',
			$this->table(),
			$student->student_ufid,
			$student->student_name,
			$student->student_email
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		$affected = $wpdb->query( $sql );
		if ( false === $affected ) {
			throw new RuntimeException( 'Adding student failed: ' . esc_html( $wpdb->last_error ) );
		}
		return 1 === $affected;
	}

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'students';
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
		return TAAS_Student::class;
	}
}
