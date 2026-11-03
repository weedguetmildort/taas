<?php
/**
 * Repository for the application_cycle table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for the current application cycle.
 */
final class TAAS_Application_Cycle_Repository extends TAAS_Repository {

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'application_cycle';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'cycle_id';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Application_Cycle::class;
	}

	/**
	 * The current cycle.
	 *
	 * @param bool $for_update Lock the row until the surrounding transaction ends.
	 * @return TAAS_Application_Cycle|null Null if no cycle has been set.
	 * @throws RuntimeException If a query fails.
	 */
	public function current( bool $for_update = false ): ?TAAS_Application_Cycle {
		global $wpdb;

		if ( ! $for_update ) {
			return $this->find( TAAS_Application_Cycle::CURRENT_ID );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE cycle_id = %d FOR UPDATE',
				$this->table(),
				TAAS_Application_Cycle::CURRENT_ID
			),
			ARRAY_A
		);

		$this->check_error();

		return null === $row ? null : TAAS_Application_Cycle::from_row( $row );
	}

	/**
	 * Save a cycle, replacing the current one.
	 *
	 * @param TAAS_Application_Cycle $cycle Cycle to save.
	 * @return void
	 * @throws RuntimeException If the write fails.
	 */
	public function save( TAAS_Application_Cycle $cycle ): void {
		global $wpdb;

		if ( false === $wpdb->replace( $this->table(), $cycle->to_row() ) ) {
			throw new RuntimeException( 'Save of application_cycle failed: ' . esc_html( $wpdb->last_error ) );
		}
	}
}
