<?php
/**
 * Row type for the academic_background table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Row from the academic_background table.
 *
 * Immutable. from_row() accepts a row from $wpdb (object or ARRAY_A) and
 * to_row() returns column => value, ready for $wpdb->insert() or $wpdb->update().
 * The auto-increment id is null on new, unsaved records and to_row() omits it then.
 */
final class TAAS_Academic_Background {

	/**
	 * Create an academic background row.
	 *
	 * @param int|null          $background_id Auto-increment id; null on a new, unsaved record.
	 * @param string            $student_ufid  UFID, 1-8 characters.
	 * @param string            $university    University name.
	 * @param TAAS_Degree_Type  $degree_type   Degree earned.
	 * @param DateTimeImmutable $grad_date     Graduation date, first of the month.
	 * @param string|null       $major         Major; may hold several, e.g. "Computer Science, Business".
	 * @param string|null       $minor         Minor.
	 * @param float|null        $gpa           GPA, 0.00-4.00.
	 * @throws InvalidArgumentException If the UFID or GPA is invalid.
	 */
	public function __construct(
		public readonly ?int $background_id,
		public readonly string $student_ufid,
		public readonly string $university,
		public readonly TAAS_Degree_Type $degree_type,
		public readonly DateTimeImmutable $grad_date, // First of the month.
		public readonly ?string $major = null,        // May hold several, e.g. "Computer Science, Business".
		public readonly ?string $minor = null,
		public readonly ?float $gpa = null,
	) {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::gpa( $gpa );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 * @throws UnexpectedValueException If degree_type or grad_date is invalid.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		$degree = TAAS_Cast::enum_or_null( TAAS_Degree_Type::class, $r['degree_type'] ?? null );
		$grad   = TAAS_Cast::date_or_null( $r['grad_date'] ?? null );

		if ( null === $degree || null === $grad ) {
			throw new UnexpectedValueException( 'Invalid academic_background row: bad degree_type or grad_date.' );
		}

		return new self(
			background_id: TAAS_Cast::int_or_null( $r['background_id'] ?? null ),
			student_ufid: (string) $r['student_ufid'],
			university: (string) $r['university'],
			degree_type: $degree,
			grad_date: $grad,
			major: TAAS_Cast::string_or_null( $r['major'] ?? null ),
			minor: TAAS_Cast::string_or_null( $r['minor'] ?? null ),
			gpa: TAAS_Cast::float_or_null( $r['gpa'] ?? null ),
		);
	}

	/**
	 * Graduation date as MM/YYYY for display.
	 */
	public function grad_label(): string {
		return $this->grad_date->format( 'm/Y' );
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$row = array(
			'student_ufid' => $this->student_ufid,
			'university'   => $this->university,
			'degree_type'  => $this->degree_type->value,
			'major'        => $this->major,
			'minor'        => $this->minor,
			'grad_date'    => $this->grad_date->format( 'Y-m-01' ),
			'gpa'          => $this->gpa,
		);

		if ( null !== $this->background_id ) {
			$row = array( 'background_id' => $this->background_id ) + $row;
		}

		return $row;
	}
}
