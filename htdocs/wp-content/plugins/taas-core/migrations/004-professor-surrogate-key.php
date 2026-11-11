<?php
/**
 * Move professors to the professor_id surrogate key and link courses by it.
 *
 * WordPress's dbDelta() cannot replace a primary key, so installs created before this change
 * kept professor_ufid as the key, and courses kept the professor_ufid column
 * and foreign key alongside an unlinked professor_id. Each step checks the
 * current state first: MySQL commits DDL immediately, so a partial run must be
 * safe to repeat, and a fresh install skips every step.
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

return function ( wpdb $wpdb ): void {
	$professors = TAAS_Schema::table( 'professors' );
	$courses    = TAAS_Schema::table( 'courses' );

	$fail = static function ( string $step ) use ( $wpdb ): void {
		throw new RuntimeException( esc_html( $step . ' failed: ' . $wpdb->last_error ) );
	};

	$has_column = static function ( string $table, string $column ) use ( $wpdb, $fail ): bool {
		$found = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table, $column ) );
		if ( '' !== $wpdb->last_error ) {
			$fail( 'Inspecting ' . $table );
		}
		return ! empty( $found );
	};

	// Column the course foreign key currently uses, or null when it is absent
	$fk_column = static function () use ( $wpdb, $courses, $fail ): ?string {
		$column = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE'
				. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
				$courses,
				'fk_courses_professor'
			)
		);
		if ( '' !== $wpdb->last_error ) {
			$fail( 'Inspecting the course foreign key' );
		}
		return $column;
	};

	// The old key blocks changing professors, so it goes first
	if ( 'professor_ufid' === $fk_column() ) {
		if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP FOREIGN KEY fk_courses_professor', $courses ) ) ) {
			$fail( 'Dropping the professor_ufid foreign key' );
		}
	}

	// Existing rows are numbered automatically; professor_ufid keeps its unique key
	if ( ! $has_column( $professors, 'professor_id' ) ) {
		$sql = $wpdb->prepare(
			'ALTER TABLE %i DROP PRIMARY KEY,'
			. ' ADD COLUMN professor_id bigint(20) unsigned NOT NULL AUTO_INCREMENT FIRST,'
			. ' ADD PRIMARY KEY (professor_id),'
			. ' MODIFY professor_ufid varchar(8) DEFAULT NULL',
			$professors
		);
		if ( false === $wpdb->query( $sql ) ) {
			$fail( 'Adding professor_id' );
		}
	}

	if ( $has_column( $courses, 'professor_ufid' ) ) {
		// Link each course to its professor before the old column is dropped
		$linked = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i c INNER JOIN %i p ON p.professor_ufid = c.professor_ufid SET c.professor_id = p.professor_id',
				$courses,
				$professors
			)
		);
		if ( false === $linked ) {
			$fail( 'Linking courses to professor_id' );
		}

		$unlinked = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i c LEFT JOIN %i p ON p.professor_id = c.professor_id WHERE p.professor_id IS NULL',
				$courses,
				$professors
			)
		);
		if ( '' !== $wpdb->last_error ) {
			$fail( 'Checking course links' );
		}
		if ( $unlinked > 0 ) {
			throw new RuntimeException( esc_html( $unlinked . ' course(s) have no matching professor; professor_ufid was kept. Fix the data and run the installer again.' ) );
		}

		$index = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $courses, 'professor_ufid' ) );
		$drop  = empty( $index ) ? 'ALTER TABLE %i DROP COLUMN professor_ufid' : 'ALTER TABLE %i DROP INDEX professor_ufid, DROP COLUMN professor_ufid';
		if ( false === $wpdb->query( $wpdb->prepare( $drop, $courses ) ) ) {
			$fail( 'Dropping courses.professor_ufid' );
		}
	}

	// The installer applies constraints before migrations, so it would not re-add this
	if ( null === $fk_column() ) {
		$sql = $wpdb->prepare(
			'ALTER TABLE %i ADD CONSTRAINT fk_courses_professor FOREIGN KEY (professor_id) REFERENCES %i (professor_id)',
			$courses,
			$professors
		);
		if ( false === $wpdb->query( $sql ) ) {
			$fail( 'Adding the professor_id foreign key' );
		}
	}
};
