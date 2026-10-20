<?php
/**
 * Repository for the academic_background table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for academic background, keyed by background_id.
 *
 * A student has many rows, one per degree.
 */
final class TAAS_Academic_Background_Repository extends TAAS_Repository {

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'academic_background';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'background_id';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Academic_Background::class;
	}

	/**
	 * Every degree a student has listed.
	 *
	 * @param string $student_ufid Student's UFID.
	 * @return TAAS_Academic_Background[]
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_student( string $student_ufid ): array {
		return $this->find_by( 'student_ufid', $student_ufid );
	}
}
