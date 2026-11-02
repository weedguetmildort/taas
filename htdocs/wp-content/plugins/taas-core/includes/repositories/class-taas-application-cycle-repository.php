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
	 * @return TAAS_Application_Cycle|null Null if no cycle has been set.
	 * @throws RuntimeException If a query fails.
	 */
	public function current(): ?TAAS_Application_Cycle {
		return $this->find( TAAS_Application_Cycle::CURRENT_ID );
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
