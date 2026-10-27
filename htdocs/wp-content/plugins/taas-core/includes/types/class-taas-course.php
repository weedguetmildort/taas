<?php
/**
 * Row type for the courses table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Row from the courses table.
 *
 * Immutable. from_row() accepts a row from $wpdb (object or ARRAY_A) and
 * to_row() returns column => value, ready for $wpdb->insert() or $wpdb->update().
 */
final class TAAS_Course {

	/**
	 * Create a course row.
	 *
	 * @param string        $course_id          Course section number.
	 * @param string        $course_code        Course code, e.g. COP3502.
	 * @param string        $course_name        Course title.
	 * @param string        $professor_ufid     Instructor's UFID, 1-8 characters.
	 * @param TAAS_Semester $course_semester    Semester the course runs in.
	 * @param int           $course_year        Year the course runs in, e.g. 2026.
	 * @param bool          $is_online          Whether the course is offered online.
	 * @param string|null   $course_description Catalog description.
	 * @param string|null   $course_notes       Professor's notes or requests about matching.
	 * @param int|null      $sections           Number of sections.
	 * @param int|null      $weekly_hours       Total TA hours required, from the staffing formula.
	 * @param int           $lab_hours          Lab hours per week.
	 * @param int           $enrollment         Current enrollment.
	 * @param int           $max_enrollment     Enrollment cap.
	 * @throws InvalidArgumentException If the professor UFID or year is invalid.
	 */
	public function __construct(
		public readonly string $course_id, // Course section number.
		public readonly string $course_code,
		public readonly string $course_name,
		public readonly string $professor_ufid,
		public readonly TAAS_Semester $course_semester,
		public readonly int $course_year,
		public readonly bool $is_online = false,
		public readonly ?string $course_description = null,
		public readonly ?string $course_notes = null,
		public readonly ?int $sections = null,
		public readonly ?int $weekly_hours = null, // Total TA hours required, from the staffing formula.
		public readonly int $lab_hours = 0,
		public readonly int $enrollment = 0,
		public readonly int $max_enrollment = 0,
	) {
		TAAS_Guard::ufid( 'professor_ufid', $professor_ufid );
		TAAS_Guard::year( 'course_year', $course_year );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 * @throws UnexpectedValueException If course_semester or course_year is missing or invalid.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		$semester = TAAS_Cast::enum_or_null( TAAS_Semester::class, $r['course_semester'] ?? null );
		$year     = TAAS_Cast::int_or_null( $r['course_year'] ?? null );

		if ( null === $semester || null === $year ) {
			throw new UnexpectedValueException( 'Invalid courses row: bad course_semester or course_year.' );
		}

		return new self(
			course_id: (string) $r['course_id'],
			course_code: (string) $r['course_code'],
			course_name: (string) $r['course_name'],
			professor_ufid: (string) $r['professor_ufid'],
			course_semester: $semester,
			course_year: $year,
			is_online: TAAS_Cast::bool( $r['is_online'] ?? 0 ),
			course_description: TAAS_Cast::string_or_null( $r['course_description'] ?? null ),
			course_notes: TAAS_Cast::string_or_null( $r['course_notes'] ?? null ),
			sections: TAAS_Cast::int_or_null( $r['sections'] ?? null ),
			weekly_hours: TAAS_Cast::int_or_null( $r['weekly_hours'] ?? null ),
			lab_hours: TAAS_Cast::int( $r['lab_hours'] ?? 0 ),
			enrollment: TAAS_Cast::int( $r['enrollment'] ?? 0 ),
			max_enrollment: TAAS_Cast::int( $r['max_enrollment'] ?? 0 ),
		);
	}

	/**
	 * Term as display text, e.g. "Summer A 2026".
	 */
	public function term_label(): string {
		return $this->course_semester->label() . ' ' . $this->course_year;
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'course_id'          => $this->course_id,
			'course_code'        => $this->course_code,
			'course_name'        => $this->course_name,
			'course_semester'    => $this->course_semester->value,
			'course_year'        => $this->course_year,
			'is_online'          => $this->is_online ? 1 : 0,
			'course_description' => $this->course_description,
			'course_notes'       => $this->course_notes,
			'sections'           => $this->sections,
			'weekly_hours'       => $this->weekly_hours,
			'lab_hours'          => $this->lab_hours,
			'enrollment'         => $this->enrollment,
			'max_enrollment'     => $this->max_enrollment,
			'professor_ufid'     => $this->professor_ufid,
		);
	}
}
