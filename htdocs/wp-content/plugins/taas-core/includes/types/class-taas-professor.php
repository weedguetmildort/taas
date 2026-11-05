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
 * The auto-increment id is null on new, unsaved records and to_row() omits it then.
 */
final class TAAS_Professor {

	/**
	 * Create a professor row.
	 *
	 * @param int|null    $professor_id    Auto-increment id; null on a new, unsaved record.
	 * @param string|null $professor_ufid  UFID, 1-8 characters; null until the professor first signs in.
	 * @param string      $professor_name  Full name.
	 * @param string      $professor_email Email address, lowercased.
	 * @throws InvalidArgumentException If the id or UFID is invalid.
	 */
	public function __construct(
		public readonly ?int $professor_id,
		public readonly ?string $professor_ufid,
		public readonly string $professor_name,
		public readonly string $professor_email,
	) {
		TAAS_Guard::course_id( 'professor_id', $professor_id );

		if ( null !== $professor_ufid ) {
			TAAS_Guard::ufid( 'professor_ufid', $professor_ufid );
		}
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		return new self(
			TAAS_Cast::int_or_null( $r['professor_id'] ?? null ),
			TAAS_Cast::string_or_null( $r['professor_ufid'] ?? null ),
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
		$row = array(
			'professor_ufid'  => $this->professor_ufid,
			'professor_name'  => $this->professor_name,
			'professor_email' => $this->professor_email,
		);

		if ( null !== $this->professor_id ) {
			$row = array( 'professor_id' => $this->professor_id ) + $row;
		}

		return $row;
	}
}
