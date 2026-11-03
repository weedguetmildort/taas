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
	 * Ordered by background_id ascending. When replacement entries have null
	 * IDs, their generated IDs preserve the submitted order (current degree first).
	 *
	 * @param string $student_ufid Student's UFID.
	 * @return TAAS_Academic_Background[]
	 * @throws InvalidArgumentException If the UFID is invalid.
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_student( string $student_ufid ): array {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		return $this->find_by( 'student_ufid', $student_ufid );
	}

	/**
	 * Replace current shared degree entries; term snapshots preserve past details.
	 *
	 * Call inside the submission transaction, after validating the entire form.
	 * The caller must roll back on failure so deletion and insertion stay atomic.
	 * Entries must have null IDs. Fresh IDs preserve the submitted display order
	 * when reading them back; entries with existing IDs are rejected before deletion.
	 *
	 * @param string                     $student_ufid Verified owner.
	 * @param TAAS_Academic_Background[] $entries      Validated degree entries.
	 * @return void
	 * @throws InvalidArgumentException If the UFID, entry type, ownership or ID is invalid.
	 * @throws RuntimeException If deletion or insertion fails.
	 */
	public function replace_for_student( string $student_ufid, array $entries ): void {
		global $wpdb;
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof TAAS_Academic_Background || $entry->student_ufid !== $student_ufid ) {
				throw new InvalidArgumentException( 'Academic-background ownership does not match.' );
			}
			if ( null !== $entry->background_id ) {
				throw new InvalidArgumentException( 'Replacement academic-background entries must have null IDs.' );
			}
		}
		if ( false === $wpdb->delete( $this->table(), array( 'student_ufid' => $student_ufid ) ) ) {
			throw new RuntimeException( 'Replacing academic background failed: ' . esc_html( $wpdb->last_error ) );
		}
		foreach ( $entries as $entry ) {
			$this->insert( $entry );
		}
	}
}
