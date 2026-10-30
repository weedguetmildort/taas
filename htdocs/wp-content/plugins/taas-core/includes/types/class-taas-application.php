<?php
/**
 * Row type for the applications table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Row from the applications table.
 *
 * Immutable. from_row() accepts a row from $wpdb (object or ARRAY_A) and
 * to_row() returns column => value, ready for $wpdb->insert() or $wpdb->update().
 * The auto-increment id is null on new, unsaved records and to_row() omits it then.
 */
final class TAAS_Application {

	/**
	 * Create an application row.
	 *
	 * @param int|null          $application_id            Auto-increment id; null on a new, unsaved record.
	 * @param string            $student_ufid              Applicant's UFID, 1-8 characters.
	 * @param string            $applied_course_id         Section number of the course applied for.
	 * @param bool              $has_taken_course          Whether the student has taken the course.
	 * @param bool              $has_ta_for_course         Whether the student has previously TA'd this course.
	 * @param string|null       $student_statement         Statement of interest.
	 * @param int|null          $student_preference_rank   Student's ranking of this course, 1 or greater.
	 * @param int|null          $professor_preference_rank Professor's ranking of this student, 1 or greater.
	 * @param TAAS_Match_Status $match_status              Matching outcome.
	 * @throws InvalidArgumentException If the UFID, course id or a rank is invalid.
	 */
	public function __construct(
		public readonly ?int $application_id,
		public readonly string $student_ufid,
		public readonly string $applied_course_id,
		public readonly bool $has_taken_course = false,
		public readonly bool $has_ta_for_course = false,
		public readonly ?string $student_statement = null,
		public readonly ?int $student_preference_rank = null,
		public readonly ?int $professor_preference_rank = null,
		public readonly TAAS_Match_Status $match_status = TAAS_Match_Status::Pending,
	) {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::course_id( 'applied_course_id', $applied_course_id );
		TAAS_Guard::rank( 'student_preference_rank', $student_preference_rank );
		TAAS_Guard::rank( 'professor_preference_rank', $professor_preference_rank );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 * @throws UnexpectedValueException If match_status is missing or invalid.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		$status = TAAS_Cast::enum_or_null( TAAS_Match_Status::class, $r['match_status'] ?? null );

		if ( null === $status ) {
			throw new UnexpectedValueException( 'Invalid applications row: missing match_status.' );
		}

		return new self(
			application_id: TAAS_Cast::int_or_null( $r['application_id'] ?? null ),
			student_ufid: (string) $r['student_ufid'],
			applied_course_id: (string) $r['applied_course_id'],
			has_taken_course: TAAS_Cast::bool( $r['has_taken_course'] ?? 0 ),
			has_ta_for_course: TAAS_Cast::bool( $r['has_ta_for_course'] ?? 0 ),
			student_statement: TAAS_Cast::string_or_null( $r['student_statement'] ?? null ),
			student_preference_rank: TAAS_Cast::int_or_null( $r['student_preference_rank'] ?? null ),
			professor_preference_rank: TAAS_Cast::int_or_null( $r['professor_preference_rank'] ?? null ),
			match_status: $status,
		);
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$row = array(
			'student_ufid'              => $this->student_ufid,
			'applied_course_id'         => $this->applied_course_id,
			'has_taken_course'          => $this->has_taken_course ? 1 : 0,
			'has_ta_for_course'         => $this->has_ta_for_course ? 1 : 0,
			'student_statement'         => $this->student_statement,
			'student_preference_rank'   => $this->student_preference_rank,
			'professor_preference_rank' => $this->professor_preference_rank,
			'match_status'              => $this->match_status->value,
		);

		if ( null !== $this->application_id ) {
			$row = array( 'application_id' => $this->application_id ) + $row;
		}

		return $row;
	}
}
