<?php
/**
 * Repository for the professors table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for professors, keyed by professor_ufid.
 */
final class TAAS_Professor_Repository extends TAAS_Repository {

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'professors';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'professor_ufid';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Professor::class;
	}

	/**
	 * One page of the professors whose name or email contains the search text, ordered by name.
	 *
	 * @param string $search Text to look for.
	 * @param int    $limit  Page size, clamped to 1..MAX_LIMIT.
	 * @param int    $offset Rows to skip, 0 or greater.
	 * @return TAAS_Professor[]
	 * @throws RuntimeException If the query fails.
	 */
	public function search( string $search, int $limit = self::DEFAULT_LIMIT, int $offset = 0 ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE professor_name LIKE %s OR professor_email LIKE %s'
				. ' ORDER BY professor_name, professor_ufid LIMIT %d OFFSET %d',
				$this->table(),
				$like,
				$like,
				max( 1, min( $limit, self::MAX_LIMIT ) ),
				max( 0, $offset )
			)
		);

		return array_map( array( TAAS_Professor::class, 'from_row' ), $rows );
	}

	/**
	 * Count the professors search() pages through.
	 *
	 * @param string $search Text to look for.
	 * @return int
	 * @throws RuntimeException If the query fails.
	 */
	public function count_search( string $search ): int {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total FROM %i WHERE professor_name LIKE %s OR professor_email LIKE %s',
				$this->table(),
				$like,
				$like
			)
		);

		return TAAS_Cast::int( $rows[0]['total'] ?? 0 );
	}
}
