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
	 * @param string      $course_id          Course section number.
	 * @param string      $course_code        Course code, e.g. COP3502.
	 * @param string      $course_name        Course title.
	 * @param string      $professor_ufid     Instructor's UFID, 1-8 characters.
	 * @param string|null $course_description Catalog description.
	 * @param string|null $course_notes       Internal notes.
	 * @param int|null    $sections           Number of sections.
	 * @param int|null    $weekly_hours       Total TA hours required, from the staffing formula.
	 * @param int         $lab_hours          Lab hours per week.
	 * @param int         $enrollment         Current enrollment.
	 * @param int         $max_enrollment     Enrollment cap.
	 * @throws InvalidArgumentException If the professor UFID is invalid.
	 */
	public function __construct(
		public readonly string $course_id, // Course section number.
		public readonly string $course_code,
		public readonly string $course_name,
		public readonly string $professor_ufid,
		public readonly ?string $course_description = null,
		public readonly ?string $course_notes = null,
		public readonly ?int $sections = null,
		public readonly ?int $weekly_hours = null, // Total TA hours required, from the staffing formula.
		public readonly int $lab_hours = 0,
		public readonly int $enrollment = 0,
		public readonly int $max_enrollment = 0,
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
			course_id: (string) $r['course_id'],
			course_code: (string) $r['course_code'],
			course_name: (string) $r['course_name'],
			professor_ufid: (string) $r['professor_ufid'],
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
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'course_id'          => $this->course_id,
			'course_code'        => $this->course_code,
			'course_name'        => $this->course_name,
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
