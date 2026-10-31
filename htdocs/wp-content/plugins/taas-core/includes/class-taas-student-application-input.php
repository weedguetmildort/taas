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

	/**
	 * Read an application JSON body. Field validation happens before any writes.
	 *
	 * @param array $body Decoded JSON body.
	 * @param bool  $allow_empty_courses Whether existing choices may all be withdrawn.
	 * @return array Normalized details, term and course preferences.
	 * @throws InvalidArgumentException If any input fails validation.
	 */
	public static function parse( array $body, bool $allow_empty_courses = false ): array {
		self::keys( $body, array( 'term', 'details', 'course_preferences' ) );
		$term = self::object( $body, 'term' );
		self::keys( $term, array( 'semester', 'year' ) );
		$semester = self::integer( $term, 'semester', 1, 5 );
		$year     = self::integer( $term, 'year', 1901, 2155 );
		$details  = self::object( $body, 'details' );
		self::keys( $details, array( 'student_name', 'student_email', 'profile', 'academic_background' ) );
		$name  = self::text( $details, 'student_name', 255 );
		$email = self::text( $details, 'student_email', 255 );
		if ( ! is_email( $email ) || ! preg_match( '/@ufl\.edu$/i', $email ) ) {
			throw new InvalidArgumentException( 'Enter a valid university email ending in @ufl.edu.' );
		}
		$profile = self::object( $details, 'profile' );
		self::keys( $profile, array( 'phone', 'country_code', 'admitted_semester', 'available_hours', 'enrollment_status', 'eap5836_status', 'eap5837_status', 'resume_url', 'english_test', 'additional_info' ) );
		$country = strtoupper( self::text( $profile, 'country_code', 2 ) );
		TAAS_Guard::country_code( $country );
		$admitted = self::text( $profile, 'admitted_semester', 20 );
		if ( ! preg_match( '/^(Spring|Summer(?: A| B| C)?|Fall) [0-9]{4}$/', $admitted ) ) {
			throw new InvalidArgumentException( 'Select a valid UF start semester and year.' );
		}
		$hours = self::integer( $profile, 'available_hours', 10, 20 );
		if ( ! in_array( $hours, array( 10, 20 ), true ) ) {
			throw new InvalidArgumentException( 'Planned TA hours must be 10 or 20.' );
		}
		// TODO: Resolve Undergraduate and Graduate mappings with the team; no placeholder values.
		$enrollment = self::integer( $profile, 'enrollment_status', 3, 4 );
		$resume     = self::text( $profile, 'resume_url', 255, false );
		if ( '' !== $resume && ( ! filter_var( $resume, FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) wp_parse_url( $resume, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) ) {
			throw new InvalidArgumentException( 'Provide a valid HTTP or HTTPS resume URL.' );
		}
		$english = null;
		if ( null !== ( $profile['english_test'] ?? null ) ) {
			$test = self::object( $profile, 'english_test' );
			self::keys( $test, array( 'type', 'score' ) );
			$type  = self::text( $test, 'type', 6 );
			$score = $test['score'] ?? null;
			if ( ! in_array( $type, array( 'TOEFL', 'SPEAK' ), true ) || ( ! is_int( $score ) && ! is_float( $score ) ) || ! is_finite( (float) $score ) || $score < 0 || $score > 2147483647 ) {
				throw new InvalidArgumentException( 'Provide a supported English test and numeric score.' );
			}
			// TODO: Finalize English score storage with the team. Rounding is temporarily approved.
			// Keep test type in the snapshot; SPEAK must never be saved as a TOEFL result.
			$english = array( 'type' => $type, 'score' => (int) round( $score, 0, PHP_ROUND_HALF_UP ) );
		}
		$normalized_profile = array(
			'phone'             => self::text( $profile, 'phone', 15 ),
			'country_code'      => $country,
			'admitted_semester' => $admitted,
			'available_hours'   => $hours,
			'enrollment_status' => $enrollment,
			'eap5836_status'    => self::integer( $profile, 'eap5836_status', 1, 4 ),
			'eap5837_status'    => self::integer( $profile, 'eap5837_status', 1, 4 ),
			'resume_url'        => '' === $resume ? null : $resume,
			'english_test'      => $english,
			'additional_info'   => self::text( $profile, 'additional_info', 1000, false ),
		);
		$backgrounds = $details['academic_background'] ?? null;
		if ( ! is_array( $backgrounds ) || ! array_is_list( $backgrounds ) || count( $backgrounds ) < 1 ) {
			throw new InvalidArgumentException( 'At least one academic-background entry is required.' );
		}
		$degrees = array();
		foreach ( $backgrounds as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new InvalidArgumentException( 'Each degree must be an object.' );
			}
			self::keys( $entry, array( 'university', 'degree_type', 'major', 'minor', 'grad_date', 'gpa' ) );
			$gpa = $entry['gpa'] ?? null;
			if ( ! array_key_exists( 'gpa', $entry ) || ( null !== $gpa && ( ( ! is_int( $gpa ) && ! is_float( $gpa ) ) || ! is_finite( (float) $gpa ) || $gpa < 0 || $gpa > 4 ) ) ) {
				throw new InvalidArgumentException( 'GPA must be 0–4, or explicitly unavailable (null).' );
			}
			$date   = self::text( $entry, 'grad_date', 10 );
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			if ( ! $parsed || $parsed->format( 'Y-m-01' ) !== $date || (int) $parsed->format( 'Y' ) < 1000 ) {
				throw new InvalidArgumentException( 'Graduation date must be the first day of its month (YYYY-MM-01).' );
			}
			$degrees[] = array(
				'university'  => self::text( $entry, 'university', 255 ),
				'degree_type' => self::integer( $entry, 'degree_type', 1, 4 ),
				'major'       => self::text( $entry, 'major', 255 ),
				'minor'       => self::text( $entry, 'minor', 255, false ),
				'grad_date'   => $date,
				'gpa'         => null === $gpa ? null : round( (float) $gpa, 2 ),
			);
		}
		$courses = $body['course_preferences'] ?? null;
		if ( ! is_array( $courses ) || ! array_is_list( $courses ) || ( ! $allow_empty_courses && count( $courses ) < 1 ) || count( $courses ) > 5 ) {
			throw new InvalidArgumentException( $allow_empty_courses ? 'Select no more than five courses.' : 'Select between one and five courses.' );
		}
		$preferences = array();
		$ids         = array();
		$ranks       = array();
		foreach ( $courses as $choice ) {
			if ( ! is_array( $choice ) ) {
				throw new InvalidArgumentException( 'Each course preference must be an object.' );
			}
			self::keys( $choice, array( 'course_id', 'rank', 'has_completed_course' ) );
			$id   = self::text( $choice, 'course_id', 20 );
			$rank = self::integer( $choice, 'rank', 1, count( $courses ) );
			if ( in_array( $id, $ids, true ) || in_array( $rank, $ranks, true ) || true !== ( $choice['has_completed_course'] ?? null ) ) {
				throw new InvalidArgumentException( 'Choose distinct courses and ranks, and confirm completion of each course.' );
			}
			$ids[]         = $id;
			$ranks[]       = $rank;
			$preferences[] = array( 'course_id' => $id, 'rank' => $rank, 'has_completed_course' => true );
		}
		usort( $preferences, static fn( array $a, array $b ) => $a['rank'] <=> $b['rank'] );
		return array(
			'term'               => array( 'semester' => $semester, 'year' => $year ),
			'details'            => array( 'student_name' => $name, 'student_email' => $email, 'profile' => $normalized_profile, 'academic_background' => $degrees ),
			'course_preferences' => $preferences,
		);
	}

	/** Validate object keys. */
	private static function keys( array $value, array $allowed ): void {
		if ( array_diff( array_keys( $value ), $allowed ) ) {
			throw new InvalidArgumentException( 'The request contains unsupported fields.' );
		}
	}

	/** Require a JSON object. */
	private static function object( array $value, string $key ): array {
		if ( ! isset( $value[ $key ] ) || ! is_array( $value[ $key ] ) || array_is_list( $value[ $key ] ) ) {
			throw new InvalidArgumentException( $key . ' must be an object.' );
		}
		return $value[ $key ];
	}

	/** Validate a bounded string without truncating submitted data. */
	private static function text( array $value, string $key, int $max, bool $required = true ): string {
		$text = $value[ $key ] ?? '';
		if ( ! is_string( $text ) || mb_strlen( trim( $text ) ) > $max || ( $required && '' === trim( $text ) ) || ( is_string( $text ) && str_contains( $text, "\0" ) ) ) {
			throw new InvalidArgumentException( $key . ' is missing or exceeds its allowed length.' );
		}
		return trim( $text );
	}

	/** Require an actual JSON integer rather than a coerced value. */
	private static function integer( array $value, string $key, int $min, int $max ): int {
		$number = $value[ $key ] ?? null;
		if ( ! is_int( $number ) || $number < $min || $number > $max ) {
			throw new InvalidArgumentException( $key . ' has an invalid value.' );
		}
		return $number;
	}
}
