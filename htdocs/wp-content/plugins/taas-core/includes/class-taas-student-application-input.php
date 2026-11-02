<?php
/**
 * Validate and normalize initial student application requests.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Explicit field allowlists; callers cannot choose owners or matching outcomes.
 */
final class TAAS_Student_Application_Input {

	// Supported country codes; regression tests check parity with the form list.
	private const COUNTRY_CODES =
		'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS'
		. ' BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE'
		. ' EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM'
		. ' HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC'
		. ' LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA'
		. ' NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW'
		. ' SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO'
		. ' TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

	/**
	 * Read an application JSON body. Field validation happens before any writes.
	 *
	 * The returned details object is stored unchanged as submitted_details JSON.
	 * Owner and term metadata and course preferences are persisted separately.
	 *
	 * @param array $body Decoded JSON body.
	 * @param bool  $allow_empty_courses Whether existing choices may all be withdrawn.
	 * @return array{
	 *   term: array{semester: int, year: int},
	 *   details: array{
	 *     student_name: string,
	 *     student_email: string,
	 *     profile: array{
	 *       phone: string, country_code: string, admitted_semester: string,
	 *       available_hours: int, enrollment_status: int,
	 *       eap5836_status: int, eap5837_status: int,
	 *       resume_url: string|null, additional_info: string,
	 *       english_test: array{type: string, score: int}|null
	 *     },
	 *     academic_background: list<array{
	 *       university: string, degree_type: int, major: string, minor: string,
	 *       grad_date: string, gpa: float|null
	 *     }>
	 *   },
	 *   course_preferences: list<array{course_id: string, rank: int, has_completed_course: true}>
	 * } Normalized values ready for the application service.
	 * @throws InvalidArgumentException If any input fails validation.
	 */
	public static function parse( array $body, bool $allow_empty_courses = false ): array {
		self::allow_only_fields( $body, array( 'term', 'details', 'course_preferences' ) );
		return array(
			'term' => self::parse_term( self::require_object( $body, 'term' ) ),
			'details' => self::parse_details( self::require_object( $body, 'details' ) ),
			'course_preferences' => self::parse_preferences( $body['course_preferences'] ?? null, $allow_empty_courses ),
		);
	}

	/** Validate the configured-term identifiers supplied with the form. */
	private static function parse_term( array $term ): array {
		self::allow_only_fields( $term, array( 'semester', 'year' ) );
		return array(
			'semester' => self::read_integer( $term, 'semester', 1, 5 ),
			'year' => self::read_integer( $term, 'year', 1901, 2155 ),
		);
	}

	/** Validate identity/contact details and the nested form sections. */
	private static function parse_details( array $details ): array {
		self::allow_only_fields( $details, array( 'student_name', 'student_email', 'profile', 'academic_background' ) );
		$name  = self::read_text( $details, 'student_name', 255 );
		$email = self::read_text( $details, 'student_email', 255 );
		if ( ! is_email( $email ) || ! preg_match( '/@ufl\.edu$/i', $email ) ) {
			throw new InvalidArgumentException( 'Enter a valid university email ending in @ufl.edu.' );
		}
		return array(
			'student_name' => $name,
			'student_email' => $email,
			'profile' => self::parse_profile( self::require_object( $details, 'profile' ) ),
			'academic_background' => self::parse_degrees( $details['academic_background'] ?? null ),
		);
	}

	/** Validate profile fields and convert optional values for storage. */
	private static function parse_profile( array $profile ): array {
		self::allow_only_fields( $profile, array(
			'phone', 'country_code', 'admitted_semester', 'available_hours', 'enrollment_status',
			'eap5836_status', 'eap5837_status', 'resume_url', 'english_test', 'additional_info',
		) );
		$country_code = strtoupper( self::read_text( $profile, 'country_code', 2 ) );
		TAAS_Guard::country_code( $country_code );
		if ( ! in_array( $country_code, explode( ' ', self::COUNTRY_CODES ), true ) ) {
			throw new InvalidArgumentException( 'Select a supported country code.' );
		}
		$admitted_semester = self::read_text( $profile, 'admitted_semester', 20 );
		if ( ! preg_match( '/^(Spring|Summer(?: A| B| C)?|Fall) [0-9]{4}$/', $admitted_semester ) ) {
			throw new InvalidArgumentException( 'Select a valid UF start semester and year.' );
		}
		$available_hours = self::read_integer( $profile, 'available_hours', 10, 20 );
		if ( ! in_array( $available_hours, array( 10, 20 ), true ) ) {
			throw new InvalidArgumentException( 'Planned TA hours must be 10 or 20.' );
		}
		// TODO: Resolve Undergraduate and Graduate mappings with the team; no placeholder values.
		$enrollment_status = self::read_integer( $profile, 'enrollment_status', 3, 4 );
		$resume_url     = self::read_text( $profile, 'resume_url', 255, false );
		if ( '' !== $resume_url ) {
			$scheme = strtolower( (string) wp_parse_url( $resume_url, PHP_URL_SCHEME ) );
			if ( ! filter_var( $resume_url, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				throw new InvalidArgumentException( 'Provide a valid HTTP or HTTPS resume URL.' );
			}
		}
		return array(
			'phone'             => self::read_text( $profile, 'phone', 15 ),
			'country_code'      => $country_code,
			'admitted_semester' => $admitted_semester,
			'available_hours'   => $available_hours,
			'enrollment_status' => $enrollment_status,
			'eap5836_status'    => self::read_integer( $profile, 'eap5836_status', 1, 4 ),
			'eap5837_status'    => self::read_integer( $profile, 'eap5837_status', 1, 4 ),
			'resume_url'        => '' === $resume_url ? null : $resume_url,
			'english_test'      => self::parse_english_test( $profile ),
			'additional_info'   => self::read_text( $profile, 'additional_info', 1000, false ),
		);
	}

	/** Preserve test type and apply the approved whole-number rounding. */
	private static function parse_english_test( array $profile ): ?array {
		if ( null === ( $profile['english_test'] ?? null ) ) {
			return null;
		}
		$test = self::require_object( $profile, 'english_test' );
		self::allow_only_fields( $test, array( 'type', 'score' ) );
		$test_type  = self::read_text( $test, 'type', 6 );
		$test_score = $test['score'] ?? null;
		if ( ! in_array( $test_type, array( 'TOEFL', 'SPEAK' ), true ) || ! self::is_number_in_range( $test_score, 0, 2147483647 ) ) {
			throw new InvalidArgumentException( 'Provide a supported English test and numeric score.' );
		}
		// TODO: Finalize English score storage with the team. Rounding is temporarily approved.
		// Keep test type in the snapshot; SPEAK must never be saved as a TOEFL result.
		return array( 'type' => $test_type, 'score' => (int) round( $test_score, 0, PHP_ROUND_HALF_UP ) );
	}

	/** Validate each degree without dropping or truncating entries. */
	private static function parse_degrees( mixed $backgrounds ): array {
		if ( ! is_array( $backgrounds ) || ! array_is_list( $backgrounds ) || count( $backgrounds ) < 1 ) {
			throw new InvalidArgumentException( 'At least one academic-background entry is required.' );
		}
		$degrees = array();
		foreach ( $backgrounds as $degree ) {
			if ( ! is_array( $degree ) ) {
				throw new InvalidArgumentException( 'Each degree must be an object.' );
			}
			self::allow_only_fields( $degree, array( 'university', 'degree_type', 'major', 'minor', 'grad_date', 'gpa' ) );
			$gpa = $degree['gpa'] ?? null;
			if ( ! array_key_exists( 'gpa', $degree ) || ( null !== $gpa && ! self::is_number_in_range( $gpa, 0, 4 ) ) ) {
				throw new InvalidArgumentException( 'GPA must be 0–4, or explicitly unavailable (null).' );
			}
			$graduation_date   = self::read_text( $degree, 'grad_date', 10 );
			$parsed_date = DateTimeImmutable::createFromFormat( '!Y-m-d', $graduation_date );
			if ( ! $parsed_date || $parsed_date->format( 'Y-m-01' ) !== $graduation_date || (int) $parsed_date->format( 'Y' ) < 1000 ) {
				throw new InvalidArgumentException( 'Graduation date must be the first day of its month (YYYY-MM-01).' );
			}
			$degrees[] = array(
				'university'  => self::read_text( $degree, 'university', 255 ),
				'degree_type' => self::read_integer( $degree, 'degree_type', 1, 4 ),
				'major'       => self::read_text( $degree, 'major', 255 ),
				'minor'       => self::read_text( $degree, 'minor', 255, false ),
				'grad_date'   => $graduation_date,
				'gpa'         => null === $gpa ? null : round( (float) $gpa, 2 ),
			);
		}
		return $degrees;
	}

	/** Validate course uniqueness/completion and normalize rank order. */
	private static function parse_preferences( mixed $course_choices, bool $allow_empty_courses ): array {
		$minimum_courses = $allow_empty_courses ? 0 : 1;
		if ( ! is_array( $course_choices ) || ! array_is_list( $course_choices )
			|| count( $course_choices ) < $minimum_courses || count( $course_choices ) > 5 ) {
			throw new InvalidArgumentException( $allow_empty_courses ? 'Select no more than five courses.' : 'Select between one and five courses.' );
		}
		$preferences = array();
		$course_ids         = array();
		$used_ranks       = array();
		foreach ( $course_choices as $choice ) {
			if ( ! is_array( $choice ) ) {
				throw new InvalidArgumentException( 'Each course preference must be an object.' );
			}
			self::allow_only_fields( $choice, array( 'course_id', 'rank', 'has_completed_course' ) );
			$course_id   = self::read_text( $choice, 'course_id', 20 );
			$rank = self::read_integer( $choice, 'rank', 1, count( $course_choices ) );
			$duplicate = in_array( $course_id, $course_ids, true ) || in_array( $rank, $used_ranks, true );
			$completed = true === ( $choice['has_completed_course'] ?? null );
			if ( $duplicate || ! $completed ) {
				throw new InvalidArgumentException( 'Choose distinct courses and ranks, and confirm completion of each course.' );
			}
			$course_ids[]         = $course_id;
			$used_ranks[]       = $rank;
			$preferences[] = array( 'course_id' => $course_id, 'rank' => $rank, 'has_completed_course' => true );
		}
		usort( $preferences, static fn( array $a, array $b ) => $a['rank'] <=> $b['rank'] );
		return $preferences;
	}

	/** JSON numbers only; strings, booleans, NaN and infinity are rejected. */
	private static function is_number_in_range( mixed $value, float $min, float $max ): bool {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) && $value >= $min && $value <= $max;
	}

	/** Validate object keys. */
	private static function allow_only_fields( array $value, array $allowed ): void {
		if ( array_diff( array_keys( $value ), $allowed ) ) {
			throw new InvalidArgumentException( 'The request contains unsupported fields.' );
		}
	}

	/** Require a JSON object. */
	private static function require_object( array $value, string $key ): array {
		if ( ! isset( $value[ $key ] ) || ! is_array( $value[ $key ] ) || array_is_list( $value[ $key ] ) ) {
			throw new InvalidArgumentException( $key . ' must be an object.' );
		}
		return $value[ $key ];
	}

	/** Validate a bounded string without truncating submitted data. */
	private static function read_text( array $value, string $key, int $max, bool $required = true ): string {
		$raw = $value[ $key ] ?? '';
		$text = is_string( $raw ) ? trim( $raw ) : null;
		if ( null === $text || mb_strlen( $text ) > $max
			|| ( $required && '' === $text ) || str_contains( $raw, "\0" ) ) {
			throw new InvalidArgumentException( $key . ' is missing or exceeds its allowed length.' );
		}
		return $text;
	}

	/** Require an actual JSON integer rather than a coerced value. */
	private static function read_integer( array $value, string $key, int $min, int $max ): int {
		$number = $value[ $key ] ?? null;
		if ( ! is_int( $number ) || $number < $min || $number > $max ) {
			throw new InvalidArgumentException( $key . ' has an invalid value.' );
		}
		return $number;
	}
}
