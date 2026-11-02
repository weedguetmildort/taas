<?php
/**
 * Submitted student details for one academic term.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * A term's saved details, independent of the student's current shared profile.
 */
final class TAAS_Student_Submission {

	/**
	 * Create a submission snapshot. Course preferences live in applications.
	 *
	 * The submitted_details value is the normalized request's details object, not the full
	 * request. Term and owner are separate columns; course choices are separate rows.
	 * The first degree is current/most recent; subsequent degrees are previous ones.
	 * Optional request fields are present after normalization: absent resume/test
	 * values become null, and absent minor/additional_info values become empty strings.
	 *
	 * Pass details validated by TAAS_Student_Application_Input::parse(), never an
	 * unfiltered request body. This model validates UFID/year and named top-level
	 * keys only; it does not enforce the nested application contract below.
	 *
	 * @param int|null      $submission_id    Saved row ID, or null for a new snapshot.
	 * @param string        $student_ufid     Verified owner identifier.
	 * @param TAAS_Semester $semester         Application semester.
	 * @param int           $year             Application year.
	 * @param array         $submitted_details Normalized application details; see the detailed shape below.
	 * @phpstan-param array{
	 *   student_name: string,
	 *   student_email: string,
	 *   profile: array{
	 *     phone: string, country_code: string, admitted_semester: string,
	 *     available_hours: int, enrollment_status: int,
	 *     eap5836_status: int, eap5837_status: int,
	 *     resume_url: string|null, additional_info: string,
	 *     english_test: array{type: string, score: int|float}|null
	 *   },
	 *   academic_background: list<array{
	 *     university: string, degree_type: int, major: string, minor: string,
	 *     grad_date: string, gpa: float|null
	 *   }>
	 * } $submitted_details Normalized application details; at least one degree.
	 * @see TAAS_Student_Application_Input::parse() Field validation and normalization.
	 * @see docs/student-application-api.md Submitted-details snapshot contract.
	 * @throws InvalidArgumentException If the owner, year or top-level detail keys are invalid.
	 */
	public function __construct(
		public readonly ?int $submission_id,
		public readonly string $student_ufid,
		public readonly TAAS_Semester $semester,
		public readonly int $year,
		public readonly array $submitted_details,
	) {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::year( 'year', $year );
		foreach ( array_keys( $submitted_details ) as $key ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Submitted details must have named fields.' );
			}
		}
	}

	/**
	 * Hydrate JSON details from a database row without consulting shared profiles.
	 *
	 * The database JSON object becomes the submitted_details PHP array documented
	 * on the constructor. Nested form fields are not revalidated when reading.
	 *
	 * @param array|object $row Saved row.
	 * @return self
	 * @throws UnexpectedValueException If the stored JSON or semester is invalid.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );
		try {
			$details = json_decode( (string) $r['submitted_details'], false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The third argument is the original exception cause, not rendered output.
			throw new UnexpectedValueException( 'Invalid submission JSON.', 0, $e );
		}
		if ( ! $details instanceof stdClass ) {
			throw new UnexpectedValueException( 'Submission details must be a JSON object.' );
		}
		$semester = TAAS_Cast::enum_or_null( TAAS_Semester::class, $r['semester'] ?? null );
		if ( null === $semester ) {
			throw new UnexpectedValueException( 'Submission semester is required.' );
		}

		return new self(
			submission_id: TAAS_Cast::int_or_null( $r['submission_id'] ?? null ),
			student_ufid: (string) $r['student_ufid'],
			semester: $semester,
			year: (int) $r['year'],
			submitted_details: json_decode( (string) $r['submitted_details'], true, 512, JSON_THROW_ON_ERROR ),
		);
	}

	/**
	 * Serialize validated details, preserving nulls and nested degree entries.
	 *
	 * The submitted_details value becomes a JSON object string; it contains only the details
	 * documented on the constructor. JSON_PRESERVE_ZERO_FRACTION preserves float GPAs.
	 *
	 * @return array{
	 *   student_ufid: string, semester: int, year: int, submitted_details: string,
	 *   submission_id?: int
	 * } Column values; the generated submission_id is omitted for new snapshots.
	 * @throws JsonException If details cannot be encoded losslessly as JSON.
	 */
	public function to_row(): array {
		$row = array(
			'student_ufid'      => $this->student_ufid,
			'semester'          => $this->semester->value,
			'year'              => $this->year,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Throw on invalid data and preserve float types rather than silently repairing input.
			'submitted_details' => json_encode( (object) $this->submitted_details, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ),
		);
		if ( null !== $this->submission_id ) {
			$row['submission_id'] = $this->submission_id;
		}
		return $row;
	}
}
