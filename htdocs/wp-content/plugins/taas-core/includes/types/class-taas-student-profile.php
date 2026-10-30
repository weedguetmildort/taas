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
	 * @param string|null                   $major             Most recent or applicable major; may hold several.
	 * @param float|null                    $gpa               Most current GPA, 0.00-4.00.
	 * @param TAAS_Enrollment_Status|null   $enrollment_status Program the student is in now; null means not yet provided.
	 * @param string|null                   $resume_url        Link to the resume.
	 * @param int|null                      $speak_score       SPEAK score, if taken.
	 * @param DateTimeImmutable|null        $speak_date        Date the SPEAK was taken.
	 * @param float|null                    $toefl_ibt_score   TOEFL iBT score, if taken.
	 * @param DateTimeImmutable|null        $toefl_ibt_date    Date the TOEFL iBT was taken.
	 * @param bool                          $has_ta_experience Whether the student has been a TA before.
	 * @param TAAS_Course_Taken_Status|null $eap5836_status    EAP 5836 status.
	 * @param TAAS_Course_Taken_Status|null $eap5837_status    EAP 5837 status.
	 * @param string|null                   $country_code      ISO 3166-1 alpha-2 code.
	 * @param string|null                   $additional_info   Notes from the student for professors and HR.
	 * @throws InvalidArgumentException If the UFID, GPA or country code is invalid.
	 */
	public function __construct(
		public readonly string $student_ufid,
		public readonly ?string $phone = null,
		public readonly ?string $admitted_semester = null,
		public readonly ?int $available_hours = null, // null = not yet provided, distinct from 0.
		public readonly ?string $major = null,
		public readonly ?float $gpa = null,
		public readonly ?TAAS_Enrollment_Status $enrollment_status = null,
		public readonly ?string $resume_url = null,
		public readonly ?int $speak_score = null,
		public readonly ?DateTimeImmutable $speak_date = null,
		public readonly ?float $toefl_ibt_score = null,
		public readonly ?DateTimeImmutable $toefl_ibt_date = null,
		public readonly bool $has_ta_experience = false,
		public readonly ?TAAS_Course_Taken_Status $eap5836_status = null,
		public readonly ?TAAS_Course_Taken_Status $eap5837_status = null,
		public readonly ?string $country_code = null,
		public readonly ?string $additional_info = null,
	) {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::gpa( $gpa );
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
			major: TAAS_Cast::string_or_null( $r['major'] ?? null ),
			gpa: TAAS_Cast::float_or_null( $r['gpa'] ?? null ),
			enrollment_status: TAAS_Cast::enum_or_null( TAAS_Enrollment_Status::class, $r['enrollment_status'] ?? null ),
			resume_url: TAAS_Cast::string_or_null( $r['resume_url'] ?? null ),
			speak_score: TAAS_Cast::int_or_null( $r['speak_score'] ?? null ),
			speak_date: TAAS_Cast::date_or_null( $r['speak_date'] ?? null ),
			toefl_ibt_score: TAAS_Cast::float_or_null( $r['toefl_ibt_score'] ?? null ),
			toefl_ibt_date: TAAS_Cast::date_or_null( $r['toefl_ibt_date'] ?? null ),
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
			'major'             => $this->major,
			'gpa'               => $this->gpa,
			'enrollment_status' => $this->enrollment_status?->value,
			'resume_url'        => $this->resume_url,
			'speak_score'       => $this->speak_score,
			'speak_date'        => $this->speak_date?->format( 'Y-m-d' ),
			'toefl_ibt_score'   => $this->toefl_ibt_score,
			'toefl_ibt_date'    => $this->toefl_ibt_date?->format( 'Y-m-d' ),
			'has_ta_experience' => $this->has_ta_experience ? 1 : 0,
			'eap5836_status'    => $this->eap5836_status?->value,
			'eap5837_status'    => $this->eap5837_status?->value,
			'country_code'      => $this->country_code,
			'additional_info'   => $this->additional_info,
		);
	}
}
