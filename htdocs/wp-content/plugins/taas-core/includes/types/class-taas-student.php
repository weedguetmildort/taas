<?php
/**
 * Row type for the students table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Row from the students table.
 *
 * Immutable. from_row() accepts a row from $wpdb (object or ARRAY_A) and
 * to_row() returns column => value, ready for $wpdb->insert() or $wpdb->update().
 */
final class TAAS_Student {

	/**
	 * Create a student row.
	 *
	 * @param string $student_ufid  UFID, 1-8 characters.
	 * @param string $student_name  Full name.
	 * @param string $student_email Email address.
	 * @throws InvalidArgumentException If the UFID is invalid.
	 */
	public function __construct(
		public readonly string $student_ufid,
		public readonly string $student_name,
		public readonly string $student_email,
	) {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		return new self(
			(string) $r['student_ufid'],
			(string) $r['student_name'],
			(string) $r['student_email'],
		);
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'student_ufid'  => $this->student_ufid,
			'student_name'  => $this->student_name,
			'student_email' => $this->student_email,
		);
	}
}
