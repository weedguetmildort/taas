<?php
/**
 * Tests for professor ranking validation
 *
 * @package TAAS_Core
 */

use PHPUnit\Framework\TestCase;

/**
 * Check that TAAS_Ranking accepts exactly 1..n rankings of live applicants
 */
class RankingTest extends TestCase {

	/**
	 * Ids of the live applications in every test
	 */
	private const ELIGIBLE = array( 101, 102, 103 );

	/**
	 * Build one ranking entry
	 *
	 * @param mixed $rank Rank value.
	 * @param mixed $id   Application id.
	 * @return array
	 */
	private static function entry( $rank, $id ): array {
		return array(
			'rank'           => $rank,
			'application_id' => $id,
		);
	}

	/**
	 * Assert parse() refuses a ranking with the given reason
	 *
	 * @param string $reason  Expected REASON_* code.
	 * @param mixed  $ranking Submitted ranking.
	 */
	private function assert_refused( string $reason, $ranking ): void {
		try {
			TAAS_Ranking::parse( $ranking, self::ELIGIBLE );
			$this->fail( 'Expected the ranking to be refused with ' . $reason . '.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertSame( $reason, $e->getMessage() );
		}
	}

	/**
	 * A valid ranking comes back as application ids in rank order, whatever order it was sent in
	 */
	public function test_valid_ranking_returns_ids_in_rank_order(): void {
		$ordered = TAAS_Ranking::parse(
			array(
				self::entry( 2, 101 ),
				self::entry( 1, 103 ),
			),
			self::ELIGIBLE
		);

		$this->assertSame( array( 103, 101 ), $ordered );
	}

	/**
	 * Every eligible applicant may be ranked
	 */
	public function test_full_ranking_is_accepted(): void {
		$ordered = TAAS_Ranking::parse(
			array(
				self::entry( 1, 102 ),
				self::entry( 2, 103 ),
				self::entry( 3, 101 ),
			),
			self::ELIGIBLE
		);

		$this->assertSame( array( 102, 103, 101 ), $ordered );
	}

	/**
	 * An empty ranking is valid and means "nobody ranked"
	 */
	public function test_empty_ranking_is_accepted(): void {
		$this->assertSame( array(), TAAS_Ranking::parse( array(), self::ELIGIBLE ) );
	}

	/**
	 * Something other than a list is refused
	 */
	public function test_non_list_is_refused(): void {
		$this->assert_refused( TAAS_Ranking::REASON_NOT_A_LIST, 'nope' );
		$this->assert_refused( TAAS_Ranking::REASON_NOT_A_LIST, null );
		$this->assert_refused( TAAS_Ranking::REASON_NOT_A_LIST, array( 'a' => self::entry( 1, 101 ) ) );
	}

	/**
	 * Entries missing a field, or with the wrong types, are refused
	 */
	public function test_malformed_entries_are_refused(): void {
		$this->assert_refused( TAAS_Ranking::REASON_MALFORMED_ENTRY, array( 'not an object' ) );
		$this->assert_refused( TAAS_Ranking::REASON_MALFORMED_ENTRY, array( array( 'rank' => 1 ) ) );
		$this->assert_refused( TAAS_Ranking::REASON_MALFORMED_ENTRY, array( array( 'application_id' => 101 ) ) );
		$this->assert_refused( TAAS_Ranking::REASON_MALFORMED_ENTRY, array( self::entry( '1', 101 ) ) );
		$this->assert_refused( TAAS_Ranking::REASON_MALFORMED_ENTRY, array( self::entry( 1.5, 101 ) ) );
		$this->assert_refused( TAAS_Ranking::REASON_MALFORMED_ENTRY, array( self::entry( 1, '101' ) ) );
		$this->assert_refused( TAAS_Ranking::REASON_MALFORMED_ENTRY, array( self::entry( 1, 0 ) ) );
		$this->assert_refused( TAAS_Ranking::REASON_MALFORMED_ENTRY, array( self::entry( 1, -1 ) ) );
	}

	/**
	 * Ranks start at 1
	 */
	public function test_rank_below_one_is_refused(): void {
		$this->assert_refused( TAAS_Ranking::REASON_RANK_OUT_OF_RANGE, array( self::entry( 0, 101 ) ) );
		$this->assert_refused( TAAS_Ranking::REASON_RANK_OUT_OF_RANGE, array( self::entry( -1, 101 ) ) );
	}

	/**
	 * Ties are refused
	 */
	public function test_duplicate_rank_is_refused(): void {
		$this->assert_refused(
			TAAS_Ranking::REASON_DUPLICATE_RANK,
			array(
				self::entry( 1, 101 ),
				self::entry( 1, 102 ),
			)
		);
	}

	/**
	 * An application cannot hold two ranks
	 */
	public function test_duplicate_application_is_refused(): void {
		$this->assert_refused(
			TAAS_Ranking::REASON_DUPLICATE_APPLICATION,
			array(
				self::entry( 1, 101 ),
				self::entry( 2, 101 ),
			)
		);
	}

	/**
	 * Ranks must be exactly 1..n
	 */
	public function test_gap_in_ranks_is_refused(): void {
		$this->assert_refused(
			TAAS_Ranking::REASON_RANK_GAP,
			array(
				self::entry( 1, 101 ),
				self::entry( 3, 102 ),
			)
		);
		$this->assert_refused( TAAS_Ranking::REASON_RANK_GAP, array( self::entry( 2, 101 ) ) );
	}

	/**
	 * Only live applications for the course may be ranked
	 */
	public function test_ineligible_application_is_refused(): void {
		$this->assert_refused(
			TAAS_Ranking::REASON_INELIGIBLE_APPLICATION,
			array(
				self::entry( 1, 101 ),
				self::entry( 2, 999 ),
			)
		);
	}
}
