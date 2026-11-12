<?php
/**
 * Repository for the match_recommendations table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for the matching algorithm's recommendations.
 */
final class TAAS_Match_Recommendation_Repository extends TAAS_Repository {

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'match_recommendations';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'recommendation_id';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Match_Recommendation::class;
	}

	/**
	 * Replace every recommendation with a new set, all or nothing.
	 *
	 * Rows are inserted in the order given, so a course's recommendations keep
	 * their priority order by recommendation_id.
	 *
	 * @param TAAS_Match_Recommendation[] $recommendations New recommendations, in priority order.
	 * @return void
	 * @throws RuntimeException If a write fails.
	 */
	public function replace_all( array $recommendations ): void {
		TAAS_Transaction::run(
			function () use ( $recommendations ) {
				global $wpdb;

				// DELETE rather than TRUNCATE, which would commit the transaction early
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );
				$this->check_error();

				foreach ( $recommendations as $recommendation ) {
					$this->insert( $recommendation );
				}
			}
		);
	}
}
