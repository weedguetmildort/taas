<?php
/**
 * Move courses from professor UFIDs to stable professor IDs.
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

return function ( wpdb $wpdb ): void {
	$professors = TAAS_Schema::table( 'professors' );
	$courses    = TAAS_Schema::table( 'courses' );

	$get_columns = static function ( string $table ) use ( $wpdb ): array {
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not inspect a TAAS table before migrating professor IDs.' );
		}

		return array_column( $columns, 'Field' );
	};

	$professor_columns = $get_columns( $professors );
	$course_columns    = $get_columns( $courses );

	if ( ! in_array( 'professor_id', $professor_columns, true )
		&& ! in_array( 'professor_ufid', $professor_columns, true ) ) {
		throw new RuntimeException( 'The professors table has no supported identifier to migrate.' );
	}

	if ( ! in_array( 'professor_ufid', $course_columns, true ) ) {
		if ( in_array( 'professor_id', $professor_columns, true ) ) {
			return;
		}
		throw new RuntimeException( 'Courses cannot be linked to professors because their UFID column is missing.' );
	}

	if ( ! in_array( 'professor_id', $course_columns, true ) ) {
		throw new RuntimeException( 'The courses table is missing professor_id; schema installation did not complete.' );
	}

	$relations = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT DISTINCT CONSTRAINT_NAME, COLUMN_NAME'
			. ' FROM information_schema.KEY_COLUMN_USAGE'
			. ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s'
			. ' AND REFERENCED_TABLE_NAME = %s',
			$courses,
			$professors
		),
		ARRAY_A
	);
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Could not inspect course-to-professor constraints.' );
	}
	foreach ( $relations as $relation ) {
		$wpdb->query(
			$wpdb->prepare(
				'ALTER TABLE %i DROP FOREIGN KEY %i',
				$courses,
				$relation['CONSTRAINT_NAME']
			)
		);
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not remove the legacy course-to-professor constraint.' );
		}
	}

	if ( ! in_array( 'professor_id', $professor_columns, true ) ) {
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $professors ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not inspect professor indexes.' );
		}
		$has_ufid_unique = array_reduce(
			$indexes,
			static fn( bool $found, array $index ): bool => $found
				|| ( 'professor_ufid' === $index['Key_name'] && 0 === (int) $index['Non_unique'] ),
			false
		);

		$alter = 'ALTER TABLE %i DROP PRIMARY KEY, MODIFY %i varchar(8) NULL,'
			. ' ADD %i bigint(20) unsigned NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (%i)';
		if ( ! $has_ufid_unique ) {
			$alter .= ', ADD UNIQUE KEY %i (%i)';
		}
		$args = array( $professors, 'professor_ufid', 'professor_id', 'professor_id' );
		if ( ! $has_ufid_unique ) {
			$args[] = 'professor_ufid';
			$args[] = 'professor_ufid';
		}
		$wpdb->query( $wpdb->prepare( $alter, ...$args ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not add stable professor IDs.' );
		}
	}

	$wpdb->query(
		$wpdb->prepare(
			'UPDATE %i c INNER JOIN %i p ON p.professor_ufid = c.professor_ufid'
			. ' SET c.professor_id = p.professor_id',
			$courses,
			$professors
		)
	);
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Could not map courses to professor IDs.' );
	}

	$unmatched = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i c LEFT JOIN %i p ON p.professor_ufid = c.professor_ufid'
			. ' WHERE p.professor_id IS NULL',
			$courses,
			$professors
		)
	);
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Could not verify course-to-professor mappings.' );
	}
	if ( 0 !== $unmatched ) {
		throw new RuntimeException( 'Some courses have no matching professor UFID; their links were not changed.' );
	}

	$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $courses, 'professor_ufid' ) );
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Could not remove the legacy course professor UFID.' );
	}

	$wpdb->query(
		$wpdb->prepare(
			'ALTER TABLE %i ADD CONSTRAINT %i FOREIGN KEY (%i) REFERENCES %i (%i)',
			$courses,
			'fk_courses_professor',
			'professor_id',
			$professors,
			'professor_id'
		)
	);
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Could not add the course-to-professor ID constraint.' );
	}
};
