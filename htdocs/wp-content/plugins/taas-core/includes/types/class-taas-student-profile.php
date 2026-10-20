<?php
/**
 * Row type for the student_profiles table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Row from the student_profiles table.
 *
 * Immutable. from_row() accepts a row from $wpdb (object or ARRAY_A) and
 * to_row() returns column => value, ready for $wpdb->insert() or $wpdb->update().
 */
final class TAAS_Student_Profile {

	/**
	 * Create a student profile row.
	 *
	 * @param string                        $student_ufid      UFID, 1-8 characters.
	 * @param string|null                   $phone             Phone number.
	 * @param string|null                   $admitted_semester Semester admitted, e.g. Fall 2024.
	 * @param int|null                      $available_hours   Weekly hours available; null means not yet provided, distinct from 0.
	 * @param TAAS_College_Status|null      $college_status    Undergraduate, master's or PhD.
	 * @param string|null                   $resume_url        Link to the resume.
	 * @param int|null                      $toefl_score       TOEFL score, if taken.
	 * @param bool                          $has_ta_experience Whether the student has been a TA before.
	 * @param TAAS_Course_Taken_Status|null $eap5836_status    EAP 5836 status.
	 * @param TAAS_Course_Taken_Status|null $eap5837_status    EAP 5837 status.
	 * @param string|null                   $country_code      ISO 3166-1 alpha-2 code.
	 * @param string|null                   $additional_info   Free-text notes from the student.
	 * @throws InvalidArgumentException If the UFID or country code is invalid.
	 */
	public function __construct(
		public readonly string $student_ufid,
		public readonly ?string $phone = null,
		public readonly ?string $admitted_semester = null,
		public readonly ?int $available_hours = null, // null = not yet provided, distinct from 0.
		public readonly ?TAAS_College_Status $college_status = null,
		public readonly ?string $resume_url = null,
		public readonly ?int $toefl_score = null,
		public readonly bool $has_ta_experience = false,
		public readonly ?TAAS_Course_Taken_Status $eap5836_status = null,
		public readonly ?TAAS_Course_Taken_Status $eap5837_status = null,
		public readonly ?string $country_code = null,
		public readonly ?string $additional_info = null,
	) {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::country_code( $country_code );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		return new self(
			student_ufid: (string) $r['student_ufid'],
			phone: TAAS_Cast::string_or_null( $r['phone'] ?? null ),
			admitted_semester: TAAS_Cast::string_or_null( $r['admitted_semester'] ?? null ),
			available_hours: TAAS_Cast::int_or_null( $r['available_hours'] ?? null ),
			college_status: TAAS_Cast::enum_or_null( TAAS_College_Status::class, $r['college_status'] ?? null ),
			resume_url: TAAS_Cast::string_or_null( $r['resume_url'] ?? null ),
			toefl_score: TAAS_Cast::int_or_null( $r['toefl_score'] ?? null ),
			has_ta_experience: TAAS_Cast::bool( $r['has_ta_experience'] ?? 0 ),
			eap5836_status: TAAS_Cast::enum_or_null( TAAS_Course_Taken_Status::class, $r['eap5836_status'] ?? null ),
			eap5837_status: TAAS_Cast::enum_or_null( TAAS_Course_Taken_Status::class, $r['eap5837_status'] ?? null ),
			country_code: TAAS_Cast::string_or_null( $r['country_code'] ?? null ),
			additional_info: TAAS_Cast::string_or_null( $r['additional_info'] ?? null ),
		);
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'student_ufid'      => $this->student_ufid,
			'phone'             => $this->phone,
			'admitted_semester' => $this->admitted_semester,
			'available_hours'   => $this->available_hours,
			'college_status'    => $this->college_status?->value,
			'resume_url'        => $this->resume_url,
			'toefl_score'       => $this->toefl_score,
			'has_ta_experience' => $this->has_ta_experience ? 1 : 0,
			'eap5836_status'    => $this->eap5836_status?->value,
			'eap5837_status'    => $this->eap5837_status?->value,
			'country_code'      => $this->country_code,
			'additional_info'   => $this->additional_info,
		);
	}
}
