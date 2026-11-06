<?php
/**
 * Repository for the professors table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for professors, keyed by professor_id.
 *
 * The professor_ufid column is null until the professor first signs in, when
 * find_or_claim() fills it in by matching their email.
 */
final class TAAS_Professor_Repository extends TAAS_Repository {

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'professors';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'professor_id';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Professor::class;
	}

	/**
	 * The professor with this UFID, if there is one.
	 *
	 * @param string $ufid UFID to match.
	 * @return TAAS_Professor|null
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_ufid( string $ufid ): ?TAAS_Professor {
		return $this->find_by( 'professor_ufid', $ufid )[0] ?? null;
	}

	/**
	 * The professor with this email, ignoring case, if there is one.
	 *
	 * @param string $email Email to match.
	 * @return TAAS_Professor|null
	 * @throws RuntimeException If the query fails.
	 */
	public function find_by_email( string $email ): ?TAAS_Professor {
		return $this->find_by( 'professor_email', self::normalize_email( $email ) )[0] ?? null;
	}

	/**
	 * The professor with this email, added with this name if there is none yet.
	 *
	 * An existing professor keeps the name they already have, so a spreadsheet
	 * that spells it differently cannot change it.
	 *
	 * @param string $email Email to match.
	 * @param string $name  Name for a new professor.
	 * @return int The professor's professor_id.
	 * @throws RuntimeException If the query fails.
	 */
	public function find_or_create( string $email, string $name ): int {
		$email    = self::normalize_email( $email );
		$existing = $this->find_by_email( $email );

		if ( null !== $existing ) {
			return (int) $existing->professor_id;
		}

		return (int) $this->insert( new TAAS_Professor( null, null, self::normalize_name( $name ), $email ) );
	}

	/**
	 * The professor signing in with this UFID, linking it to them on their first sign-in.
	 *
	 * A professor already holding the UFID is returned as is. Otherwise the
	 * professor with one of these emails and no UFID yet is given it.
	 *
	 * @param string   $ufid   UFID from Shibboleth.
	 * @param string[] $emails Emails the professor may have been added under.
	 * @return TAAS_Professor|null Null if the UFID belongs to no professor.
	 * @throws RuntimeException If a query fails.
	 */
	public function find_or_claim( string $ufid, array $emails ): ?TAAS_Professor {
		global $wpdb;

		$professor = $this->find_by_ufid( $ufid );

		if ( null !== $professor ) {
			return $professor;
		}

		foreach ( array_unique( array_filter( array_map( array( self::class, 'normalize_email' ), $emails ) ) ) as $email ) {
			$claimed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET professor_ufid = %s WHERE professor_email = %s AND professor_ufid IS NULL',
					$this->table(),
					$ufid,
					$email
				)
			);

			$this->check_error();

			if ( $claimed > 0 ) {
				return $this->find_by_ufid( $ufid );
			}
		}

		return null;
	}

	/**
	 * An email as stored: trimmed and lowercased.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * A name as stored: trimmed, with runs of whitespace collapsed to one space.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function normalize_name( string $name ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
	}

	/**
	 * One page of the professors whose name or email contains the search text, ordered by name.
	 *
	 * @param string $search Text to look for.
	 * @param int    $limit  Page size, clamped to 1..MAX_LIMIT.
	 * @param int    $offset Rows to skip, 0 or greater.
	 * @return TAAS_Professor[]
	 * @throws RuntimeException If the query fails.
	 */
	public function search( string $search, int $limit = self::DEFAULT_LIMIT, int $offset = 0 ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE professor_name LIKE %s OR professor_email LIKE %s'
				. ' ORDER BY professor_name, professor_id LIMIT %d OFFSET %d',
				$this->table(),
				$like,
				$like,
				max( 1, min( $limit, self::MAX_LIMIT ) ),
				max( 0, $offset )
			)
		);

		return array_map( array( TAAS_Professor::class, 'from_row' ), $rows );
	}

	/**
	 * Count the professors search() pages through.
	 *
	 * @param string $search Text to look for.
	 * @return int
	 * @throws RuntimeException If the query fails.
	 */
	public function count_search( string $search ): int {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$rows = $this->select_rows(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total FROM %i WHERE professor_name LIKE %s OR professor_email LIKE %s',
				$this->table(),
				$like,
				$like
			)
		);

		return TAAS_Cast::int( $rows[0]['total'] ?? 0 );
	}
}
