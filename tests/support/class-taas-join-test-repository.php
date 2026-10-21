<?php
/**
 * Repository with a public door onto select_rows(), for the repository tests
 *
 * @package TAAS_Core
 */

/**
 * Repository with a public door onto select_rows(), as a join method would use it
 */
class TAAS_Join_Test_Repository extends TAAS_Repository {

	/**
	 * Bare table name
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'courses';
	}

	/**
	 * Primary key column
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'course_id';
	}

	/**
	 * Row class
	 *
	 * @return string
	 */
	protected function row_class(): string {
		return TAAS_Course::class;
	}

	/**
	 * Run a prepared query through select_rows()
	 *
	 * @param string $sql Prepared SELECT.
	 * @return array
	 */
	public function joined( string $sql ): array {
		return $this->select_rows( $sql );
	}
}
