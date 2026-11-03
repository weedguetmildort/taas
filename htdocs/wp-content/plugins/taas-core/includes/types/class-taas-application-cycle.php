<?php
/**
 * Row type for the application_cycle table.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * The current application cycle: one term's run through the stages.
 *
 * Immutable. from_row() accepts a row from $wpdb (object or ARRAY_A) and
 * to_row() returns column => value, ready for $wpdb->replace().
 *
 * $stage_dates holds only the stages that have dates, keyed by stage label, as
 * array{start_at: ?DateTimeImmutable, end_at: ?DateTimeImmutable}. All dates are UTC.
 * The active stage is not stored: active_stage() works it out from these dates.
 */
final class TAAS_Application_Cycle {

	/**
	 * The primary key of the one row this table holds.
	 */
	public const CURRENT_ID = 1;

	/**
	 * Timestamp format used by the API.
	 */
	private const API_DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * Create an application cycle row.
	 *
	 * @param TAAS_Semester $cycle_semester Semester of the cycle.
	 * @param int           $cycle_year     Year of the cycle.
	 * @param array         $stage_dates    Dates by stage label, see the class docblock.
	 * @throws InvalidArgumentException If the year is invalid.
	 */
	public function __construct(
		public readonly TAAS_Semester $cycle_semester,
		public readonly int $cycle_year,
		public readonly array $stage_dates = array(),
	) {
		TAAS_Guard::year( 'cycle_year', $cycle_year );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 * @throws UnexpectedValueException If cycle_semester is missing or invalid.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		$semester = TAAS_Cast::enum_or_null( TAAS_Semester::class, $r['cycle_semester'] ?? null );

		if ( null === $semester ) {
			throw new UnexpectedValueException( 'Invalid application_cycle row: bad cycle_semester.' );
		}

		$dates = array();

		foreach ( TAAS_Cycle_Stage::cases() as $stage ) {
			$start_at = TAAS_Cast::utc_datetime_or_null( $r[ $stage->label() . '_start_at' ] ?? null );
			$end_at   = TAAS_Cast::utc_datetime_or_null( $r[ $stage->label() . '_end_at' ] ?? null );

			if ( null !== $start_at || null !== $end_at ) {
				$dates[ $stage->label() ] = array(
					'start_at' => $start_at,
					'end_at'   => $end_at,
				);
			}
		}

		return new self(
			$semester,
			TAAS_Cast::int( $r['cycle_year'] ?? 0 ),
			$dates
		);
	}

	/**
	 * Parse an API timestamp, e.g. 2026-11-16T04:59:59Z.
	 *
	 * @param string $value Timestamp from a request.
	 * @return DateTimeImmutable|null Null if it is not a real UTC timestamp in that format.
	 */
	public static function parse_api_date( string $value ): ?DateTimeImmutable {
		// createFromFormat() throws a ValueError on a null byte, which JSON can send as \u0000.
		if ( str_contains( $value, "\0" ) ) {
			return null;
		}

		$date   =DateTimeImmutable::createFromFormat( '!' . self::API_DATE_FORMAT, $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();

		// Reject impossible dates (2026-02-31) that PHP would otherwise roll over.
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date;
	}

	/**
	 * The stage active at a given time.
	 *
	 * That is the stage with the latest start_at to have come, unless its end_at
	 * has also come. It is not "any stage whose dates contain now": an earlier
	 * stage with no end_at would then reopen when a later stage ended.
	 *
	 * @param DateTimeImmutable|null $now Time to check; null for the real clock.
	 * @return TAAS_Cycle_Stage|null Null if no stage is active.
	 */
	public function active_stage( ?DateTimeImmutable $now = null ): ?TAAS_Cycle_Stage {
		$now    = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$active = null;
		$latest = null;

		foreach ( TAAS_Cycle_Stage::cases() as $stage ) {
			$start_at = $this->stage_dates[ $stage->label() ]['start_at'] ?? null;

			if ( null !== $start_at && $start_at <= $now && ( null === $latest || $start_at > $latest ) ) {
				$active = $stage;
				$latest = $start_at;
			}
		}

		$end_at = null === $active ? null : ( $this->stage_dates[ $active->label() ]['end_at'] ?? null );

		return null !== $end_at && $end_at <= $now ? null : $active;
	}

	/**
	 * Return the row as column => value, ready for $wpdb->replace().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$row = array(
			'cycle_id'       => self::CURRENT_ID,
			'cycle_semester' => $this->cycle_semester->value,
			'cycle_year'     => $this->cycle_year,
		);

		foreach ( TAAS_Cycle_Stage::cases() as $stage ) {
			$dates = $this->stage_dates[ $stage->label() ] ?? null;

			$row[ $stage->label() . '_start_at' ] = ( $dates['start_at'] ?? null )?->format( 'Y-m-d H:i:s' );
			$row[ $stage->label() . '_end_at' ]   = ( $dates['end_at'] ?? null )?->format( 'Y-m-d H:i:s' );
		}

		return $row;
	}

	/**
	 * Return the cycle in the shape the API sends.
	 *
	 * @param DateTimeImmutable|null $now Time to report the active stage for; null for the real clock.
	 * @return array<string, mixed>
	 */
	public function to_response( ?DateTimeImmutable $now = null ): array {
		$stage_dates = array();

		foreach ( TAAS_Cycle_Stage::cases() as $stage ) {
			$dates = $this->stage_dates[ $stage->label() ] ?? null;

			$stage_dates[ $stage->label() ] = null === $dates ? null : array(
				'start_at' => $dates['start_at']?->format( self::API_DATE_FORMAT ),
				'end_at'   => $dates['end_at']?->format( self::API_DATE_FORMAT ),
			);
		}

		return array(
			'term'        => $this->cycle_semester->label(),
			'year'        => $this->cycle_year,
			'stage'       => $this->active_stage( $now )?->label(),
			'stage_dates' => $stage_dates,
		);
	}
}
