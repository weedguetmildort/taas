<?php
/**
 * Base class for table repositories.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for one table.
 *
 * A repository is the only place that talks to $wpdb for its table. It takes
 * and returns the typed row classes (TAAS_Course and so on), so callers never
 * handle raw rows or SQL. Each subclass names its table, primary key and row
 * class; everything else is shared.
 *
 * Failed queries throw RuntimeException, matching the CSV importer. A missing
 * row is not a failure: find() returns null.
 */
abstract class TAAS_Repository {

	/**
	 * Default page size for all().
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * Largest page size any caller can request.
	 */
	public const MAX_LIMIT = 100;

	/**
	 * Bare table name, e.g. 'courses'.
	 *
	 * @return string
	 */
	abstract protected function bare_table(): string;

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	abstract protected function primary_key(): string;

	/**
	 * Row class with a static from_row() and an instance to_row().
	 * Usually references a class under ../types/
	 *
	 * @return class-string
	 */
	abstract protected function row_class(): string;

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return TAAS_Schema::table( $this->bare_table() );
	}

	/**
	 * Find one row by primary key.
	 *
	 * @param string|int $id Primary key value.
	 * @return object|null Row object, or null if there is no such row.
	 * @throws RuntimeException If the query fails.
	 */
	public function find( string|int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s', $this->table(), $this->primary_key(), (string) $id ),
			ARRAY_A
		);

		$this->check_error();

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * List rows, ordered by primary key.
	 *
	 * @param int $limit  Page size, clamped to 1..MAX_LIMIT.
	 * @param int $offset Rows to skip, 0 or greater.
	 * @return object[]
	 * @throws RuntimeException If the query fails.
	 */
	public function all( int $limit = self::DEFAULT_LIMIT, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY %i LIMIT %d OFFSET %d',
				$this->table(),
				$this->primary_key(),
				max( 1, min( $limit, self::MAX_LIMIT ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		$this->check_error();

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Count every row in the table.
	 *
	 * @return int
	 * @throws RuntimeException If the query fails.
	 */
	public function count(): int {
		global $wpdb;

		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) );

		$this->check_error();

		return (int) $total;
	}

	/**
	 * Insert a row.
	 *
	 * @param object $entity Row object of this repository's row class.
	 * @return string|int Insert id for auto-increment tables, otherwise the primary key value.
	 * @throws RuntimeException If the insert fails.
	 */
	public function insert( object $entity ): string|int {
		global $wpdb;

		$this->assert_row_class( $entity );

		$data = $entity->to_row();

		if ( false === $wpdb->insert( $this->table(), $data ) ) {
			throw new RuntimeException( 'Insert into ' . esc_html( $this->bare_table() ) . ' failed: ' . esc_html( $wpdb->last_error ) );
		}

		return (int) $wpdb->insert_id > 0 ? (int) $wpdb->insert_id : (string) $data[ $this->primary_key() ];
	}

	/**
	 * Update the row with the given primary key.
	 *
	 * The primary key is never written, so a row cannot be renamed through here.
	 *
	 * @param string|int $id     Primary key of the row to update.
	 * @param object     $entity Row object holding the new values.
	 * @return bool True if a row changed, false if it matched but held the same values.
	 * @throws RuntimeException If the update fails.
	 */
	public function update( string|int $id, object $entity ): bool {
		global $wpdb;

		$this->assert_row_class( $entity );

		$data = $entity->to_row();
		unset( $data[ $this->primary_key() ] );

		$changed = $wpdb->update( $this->table(), $data, array( $this->primary_key() => $id ) );

		if ( false === $changed ) {
			throw new RuntimeException( 'Update of ' . esc_html( $this->bare_table() ) . ' failed: ' . esc_html( $wpdb->last_error ) );
		}

		return $changed > 0;
	}

	/**
	 * Delete the row with the given primary key.
	 *
	 * @param string|int $id Primary key of the row to delete.
	 * @return bool True if a row was deleted.
	 * @throws RuntimeException If the delete fails.
	 */
	public function delete( string|int $id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete( $this->table(), array( $this->primary_key() => $id ) );

		if ( false === $deleted ) {
			throw new RuntimeException( 'Delete from ' . esc_html( $this->bare_table() ) . ' failed: ' . esc_html( $wpdb->last_error ) );
		}

		return $deleted > 0;
	}

	/**
	 * Find rows where one column equals a value, ordered by primary key.
	 *
	 * Protected so a subclass exposes only the lookups its table really needs,
	 * under names that read well (find_by_student and so on). The column name
	 * must come from the subclass, never from request input.
	 *
	 * @param string     $column Column to match.
	 * @param string|int $value  Value to match.
	 * @return object[]
	 * @throws RuntimeException If the query fails.
	 */
	protected function find_by( string $column, string|int $value ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE %i = %s ORDER BY %i',
				$this->table(),
				$column,
				(string) $value,
				$this->primary_key()
			),
			ARRAY_A
		);

		$this->check_error();

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Turn a raw row into this repository's row object.
	 *
	 * @param array $row Raw row from $wpdb.
	 * @return object
	 */
	private function hydrate( array $row ): object {
		$class = $this->row_class();

		return $class::from_row( $row );
	}

	/**
	 * Reject an entity that belongs to a different table.
	 *
	 * @param object $entity Row object to check.
	 * @return void
	 * @throws InvalidArgumentException If the entity is the wrong class.
	 */
	private function assert_row_class( object $entity ): void {
		$class = $this->row_class();

		if ( ! $entity instanceof $class ) {
			throw new InvalidArgumentException( esc_html( static::class . ' expects ' . $class . ', got ' . $entity::class . '.' ) );
		}
	}

	/**
	 * Throw if the last query left an error on $wpdb.
	 *
	 * @return void
	 * @throws RuntimeException If $wpdb->last_error is set.
	 */
	private function check_error(): void {
		global $wpdb;

		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Query on ' . esc_html( $this->bare_table() ) . ' failed: ' . esc_html( $wpdb->last_error ) );
		}
	}
}
