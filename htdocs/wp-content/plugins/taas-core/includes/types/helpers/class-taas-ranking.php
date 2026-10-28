<?php
/**
 * Validation for a professor's submitted applicant ranking.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Turn a submitted ranking into an ordered list of student UFIDs, or refuse it.
 *
 * A ranking arrives as a list of { "rank": int, "student_ufid": string }
 * entries. It is accepted only if the ranks are exactly 1..n with no ties or
 * gaps, no student appears twice, and every student has a live application
 * for the course. Applicants left out are unranked (equal last), so an empty
 * list is valid and clears the ranking.
 *
 * On failure parse() throws InvalidArgumentException whose message is one of
 * the REASON_* codes, so the REST layer can pass a stable, machine-readable
 * reason to the frontend.
 */
final class TAAS_Ranking {

	/**
	 * The ranking is not a list.
	 */
	public const REASON_NOT_A_LIST = 'not_a_list';

	/**
	 * An entry is not an object with an integer rank and a string student_ufid.
	 */
	public const REASON_MALFORMED_ENTRY = 'malformed_entry';

	/**
	 * A rank is below 1.
	 */
	public const REASON_RANK_OUT_OF_RANGE = 'rank_out_of_range';

	/**
	 * Two entries share a rank.
	 */
	public const REASON_DUPLICATE_RANK = 'duplicate_rank';

	/**
	 * The same student appears more than once.
	 */
	public const REASON_DUPLICATE_STUDENT = 'duplicate_student';

	/**
	 * The ranks are not exactly 1..n.
	 */
	public const REASON_RANK_GAP = 'rank_gap';

	/**
	 * A student has no live application for the course.
	 */
	public const REASON_INELIGIBLE_STUDENT = 'ineligible_student';

	/**
	 * Validate a ranking and return the students in rank order.
	 *
	 * @param mixed    $ranking        Submitted ranking, normally the decoded JSON list.
	 * @param string[] $eligible_ufids UFIDs of every non-withdrawn applicant to the course.
	 * @return string[] Student UFIDs, rank 1 first.
	 * @throws InvalidArgumentException With a REASON_* code as the message if the ranking is invalid.
	 */
	public static function parse( mixed $ranking, array $eligible_ufids ): array {
		if ( ! is_array( $ranking ) || ! array_is_list( $ranking ) ) {
			throw new InvalidArgumentException( esc_html( self::REASON_NOT_A_LIST ) );
		}

		$by_rank = array();
		$seen    = array();

		foreach ( $ranking as $entry ) {
			if ( ! is_array( $entry ) || ! is_int( $entry['rank'] ?? null ) || ! is_string( $entry['student_ufid'] ?? null ) || '' === $entry['student_ufid'] ) {
				throw new InvalidArgumentException( esc_html( self::REASON_MALFORMED_ENTRY ) );
			}

			$rank = $entry['rank'];
			$ufid = $entry['student_ufid'];

			if ( $rank < 1 ) {
				throw new InvalidArgumentException( esc_html( self::REASON_RANK_OUT_OF_RANGE ) );
			}

			if ( isset( $by_rank[ $rank ] ) ) {
				throw new InvalidArgumentException( esc_html( self::REASON_DUPLICATE_RANK ) );
			}

			// Prefixed so a numeric UFID stays a string key.
			if ( isset( $seen[ 'u' . $ufid ] ) ) {
				throw new InvalidArgumentException( esc_html( self::REASON_DUPLICATE_STUDENT ) );
			}

			$by_rank[ $rank ]    = $ufid;
			$seen[ 'u' . $ufid ] = true;
		}

		// Ranks are unique and at least 1, so 1..n is present exactly when the largest is n.
		if ( array() !== $by_rank && max( array_keys( $by_rank ) ) !== count( $by_rank ) ) {
			throw new InvalidArgumentException( esc_html( self::REASON_RANK_GAP ) );
		}

		$eligible = array_map( 'strval', $eligible_ufids );

		foreach ( $by_rank as $ufid ) {
			if ( ! in_array( $ufid, $eligible, true ) ) {
				throw new InvalidArgumentException( esc_html( self::REASON_INELIGIBLE_STUDENT ) );
			}
		}

		ksort( $by_rank );

		return array_values( $by_rank );
	}
}
