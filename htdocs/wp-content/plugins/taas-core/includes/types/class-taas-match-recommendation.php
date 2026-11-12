<?php
/**
 * Row type for the match_recommendations table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Row from the match_recommendations table: one student the matching algorithm
 * recommends for one course.
 *
 * Immutable. from_row() accepts a row from $wpdb (object or ARRAY_A) and
 * to_row() returns column => value, ready for $wpdb->insert().
 */
final class TAAS_Match_Recommendation {

	/**
	 * Create a recommendation row.
	 *
	 * @param int|null $recommendation_id Auto-increment id; null on a new, unsaved record.
	 * @param int      $course_id         Course the student is recommended for.
	 * @param string   $student_ufid      Recommended student's UFID.
	 * @param int      $recommended_hours Weekly hours the student would give the course.
	 * @param string   $reason            Tier that made the match, e.g. professor_preference_1.
	 * @throws InvalidArgumentException If the course ID or UFID is invalid.
	 */
	public function __construct(
		public readonly ?int $recommendation_id,
		public readonly int $course_id,
		public readonly string $student_ufid,
		public readonly int $recommended_hours,
		public readonly string $reason,
	) {
		TAAS_Guard::course_id( 'course_id', $course_id );
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
			TAAS_Cast::int_or_null( $r['recommendation_id'] ?? null ),
			TAAS_Cast::int( $r['course_id'] ),
			(string) $r['student_ufid'],
			TAAS_Cast::int( $r['recommended_hours'] ),
			(string) $r['reason'],
		);
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert().
	 *
	 * The id is left out when null so the database assigns one.
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$row = array(
			'course_id'         => $this->course_id,
			'student_ufid'      => $this->student_ufid,
			'recommended_hours' => $this->recommended_hours,
			'reason'            => $this->reason,
		);

		if ( null !== $this->recommendation_id ) {
			$row = array( 'recommendation_id' => $this->recommendation_id ) + $row;
		}

		return $row;
	}
}
