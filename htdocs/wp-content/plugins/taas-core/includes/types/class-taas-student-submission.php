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
	 * TODO: Finalize the snapshot field contract with the team. Callers must
	 * pass validated submitted details, never an unfiltered request body.
	 *
	 * @param int|null      $submission_id    Saved row ID, or null for a new snapshot.
	 * @param string        $student_ufid     Verified owner identifier.
	 * @param TAAS_Semester $semester         Application semester.
	 * @param int           $year             Application year.
	 * @param array         $submitted_details Submitted identity, profile and academic details.
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
	 * @param array|object $row Saved row.
	 * @return self
	 * @throws UnexpectedValueException If the stored details are not a JSON object.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );
		try {
			$details = json_decode( (string) $r['submitted_details'], false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( 'Invalid submission JSON.', 0, $e );
		}
		if ( ! $details instanceof stdClass ) {
			throw new UnexpectedValueException( 'Submission details must be a JSON object.' );
		}

		return new self(
			submission_id: TAAS_Cast::int_or_null( $r['submission_id'] ?? null ),
			student_ufid: (string) $r['student_ufid'],
			semester: TAAS_Semester::from( (int) $r['semester'] ),
			year: (int) $r['year'],
			submitted_details: json_decode( (string) $r['submitted_details'], true, 512, JSON_THROW_ON_ERROR ),
		);
	}

	/**
	 * Serialize validated details, preserving nulls and nested degree entries.
	 *
	 * @return array Column values for the repository.
	 * @throws JsonException If details cannot be encoded losslessly as JSON.
	 */
	public function to_row(): array {
		$row = array(
			'student_ufid'      => $this->student_ufid,
			'semester'          => $this->semester->value,
			'year'              => $this->year,
			'submitted_details' => json_encode( (object) $this->submitted_details, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ),
		);
		if ( null !== $this->submission_id ) {
			$row['submission_id'] = $this->submission_id;
		}
		return $row;
	}
}
