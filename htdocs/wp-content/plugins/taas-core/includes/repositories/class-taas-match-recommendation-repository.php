<?php
/**
 * Repository for the match_recommendations table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Data access for the matching algorithm's recommendations.
 */
final class TAAS_Match_Recommendation_Repository extends TAAS_Repository {

	/**
	 * Bare table name.
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'match_recommendations';
	}

	/**
	 * Primary key column.
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'recommendation_id';
	}

	/**
	 * Row class.
	 *
	 * @return class-string
	 */
	protected function row_class(): string {
		return TAAS_Match_Recommendation::class;
	}

	/**
	 * Professor names and top ranked applicants for a page of courses.
	 *
	 * @param int[] $course_ids Course IDs.
	 * @return array<int, array{professor_name: string, top_ranked_student: ?string}> Details keyed by course ID.
	 */
	public function course_details( array $course_ids ): array {
		global $wpdb;

		if ( empty( $course_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		$rows         = $this->select_rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only %d placeholders are joined in; every value is bound.
			$wpdb->prepare(
				'SELECT c.course_id, p.professor_name, s.student_name'
				. ' FROM %i c INNER JOIN %i p ON p.professor_id = c.professor_id'
				. ' LEFT JOIN %i a ON a.application_id = ('
				. ' SELECT ranked.application_id FROM %i ranked'
				. ' WHERE ranked.applied_course_id = c.course_id AND ranked.professor_preference_rank IS NOT NULL'
				. ' AND ranked.match_status <> %d ORDER BY ranked.professor_preference_rank, ranked.application_id LIMIT 1)'
				. ' LEFT JOIN %i s ON s.student_ufid = a.student_ufid'
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders are joined in.
				. " WHERE c.course_id IN ({$placeholders})",
				TAAS_Schema::table( 'courses' ),
				TAAS_Schema::table( 'professors' ),
				TAAS_Schema::table( 'applications' ),
				TAAS_Schema::table( 'applications' ),
				TAAS_Match_Status::Withdrawn->value,
				TAAS_Schema::table( 'students' ),
				...$course_ids
			)
		);

		$details = array();
		foreach ( $rows as $row ) {
			$details[ (int) $row['course_id'] ] = array(
				'professor_name'     => (string) $row['professor_name'],
				'top_ranked_student' => $row['student_name'],
			);
		}

		return $details;
	}

	/**
	 * Every recommendation for the given courses, in the order the algorithm made them.
	 *
	 * @param int[] $course_ids Course IDs.
	 * @return array<int, array{application_id: int, course_id: int, student_ufid: string, student_name: string, hours: int, reason: string}>
	 * @throws RuntimeException If the query fails.
	 */
	public function for_courses( array $course_ids ): array {
		global $wpdb;

		if ( empty( $course_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

		$rows = $this->select_rows(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only %d placeholders are joined in; every value is bound.
			$wpdb->prepare(
				'SELECT a.application_id, r.course_id, r.student_ufid, s.student_name, r.recommended_hours, r.reason'
				. ' FROM %i r'
				. ' INNER JOIN %i a ON a.student_ufid = r.student_ufid AND a.applied_course_id = r.course_id'
				. ' INNER JOIN %i s ON s.student_ufid = r.student_ufid'
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders are joined in.
				. " WHERE r.course_id IN ({$placeholders})"
				. ' ORDER BY r.recommendation_id',
				$this->table(),
				TAAS_Schema::table( 'applications' ),
				TAAS_Schema::table( 'students' ),
				...$course_ids
			)
		);

		return array_map(
			static fn( array $r ) => array(
				'application_id' => TAAS_Cast::int( $r['application_id'] ),
				'course_id'      => TAAS_Cast::int( $r['course_id'] ),
				'student_ufid'   => (string) $r['student_ufid'],
				'student_name'   => (string) $r['student_name'],
				'hours'          => TAAS_Cast::int( $r['recommended_hours'] ),
				'reason'         => (string) $r['reason'],
			),
			$rows
		);
	}

	/**
	 * Replace every recommendation with a new set, all or nothing.
	 *
	 * Rows are inserted in the order given, so a course's recommendations keep
	 * their priority order by recommendation_id.
	 *
	 * @param TAAS_Match_Recommendation[] $recommendations New recommendations, in priority order.
	 * @return void
	 * @throws RuntimeException If a write fails.
	 */
	public function replace_all( array $recommendations ): void {
		TAAS_Transaction::run(
			function () use ( $recommendations ) {
				global $wpdb;

				// DELETE rather than TRUNCATE, which would commit the transaction early
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table() ) );
				$this->check_error();

				foreach ( $recommendations as $recommendation ) {
					$this->insert( $recommendation );
				}
			}
		);
	}
}
