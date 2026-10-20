<?php
/**
 * Row type for the professors table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Row from the professors table.
 *
 * Immutable. from_row() accepts a row from $wpdb (object or ARRAY_A) and
 * to_row() returns column => value, ready for $wpdb->insert() or $wpdb->update().
 */
final class TAAS_Professor {

	/**
	 * Create a professor row.
	 *
	 * @param string $professor_ufid  UFID, 1-8 characters.
	 * @param string $professor_name  Full name.
	 * @param string $professor_email Email address.
	 * @throws InvalidArgumentException If the UFID is invalid.
	 */
	public function __construct(
		public readonly string $professor_ufid,
		public readonly string $professor_name,
		public readonly string $professor_email,
	) {
		TAAS_Guard::ufid( 'professor_ufid', $professor_ufid );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		return new self(
			(string) $r['professor_ufid'],
			(string) $r['professor_name'],
			(string) $r['professor_email'],
		);
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'professor_ufid'  => $this->professor_ufid,
			'professor_name'  => $this->professor_name,
			'professor_email' => $this->professor_email,
		);
	}
}
