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
	 * Save identity and lock this student for the surrounding transaction.
	 *
	 * Initial submissions use the lock to serialize duplicate requests for a
	 * student. Only verified account identity may supply student_ufid.
	 *
	 * @param TAAS_Student $student Validated identity.
	 * @return void
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
