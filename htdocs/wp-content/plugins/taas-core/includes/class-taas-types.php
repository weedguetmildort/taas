<?php
/**
 * Typed value objects and enums that encapsulate rows from the TAAS core tables.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/*
 * ------------------------------------------------------------------------
 * Enums
 * ------------------------------------------------------------------------
 */

/**
 * Values for student_profiles.college_status.
 */
enum TAAS_College_Status: int {
	case Undergrad = 1;
	case Masters   = 2;
	case Phd       = 3;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Undergrad => 'Undergraduate',
			self::Masters   => 'Master\'s',
			self::Phd       => 'PhD',
		};
	}
}

/**
 * Values for academic_background.degree_type.
 */
enum TAAS_Degree_Type: int {
	case AA  = 1;
	case BS  = 2;
	case MS  = 3;
	case Phd = 4;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::AA  => 'AA',
			self::BS  => 'BS',
			self::MS  => 'MS',
			self::Phd => 'PhD',
		};
	}
}

/**
 * Values for student_profiles.eap5836_status and eap5837_status.
 */
enum TAAS_Course_Taken_Status: int {
	case Taken_At_UF     = 1;
	case Taken_Elsewhere = 2;
	case Taking          = 3;
	case Not_Taken       = 4;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Taken_At_UF     => 'Taken at UF',
			self::Taken_Elsewhere => 'Taken elsewhere',
			self::Taking          => 'Currently taking',
			self::Not_Taken       => 'Not taken',
		};
	}
}

/**
 * Values for applications.match_status.
 */
enum TAAS_Match_Status: int {
	case Pending   = 1;
	case Matched   = 2;
	case Rejected  = 3;
	case Withdrawn = 4;

	/**
	 * Human-readable name for display.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Pending   => 'Pending',
			self::Matched   => 'Matched',
			self::Rejected  => 'Rejected',
			self::Withdrawn => 'Withdrawn',
		};
	}
}

/*
 * ------------------------------------------------------------------------
 * Casting helpers
 * ------------------------------------------------------------------------
 */

/**
 * Convert the strings $wpdb returns into native PHP types.
 */
final class TAAS_Cast {

	/**
	 * Cast a raw column value to int.
	 *
	 * @param mixed $value Raw column value.
	 * @return int
	 */
	public static function int( mixed $value ): int {
		return (int) $value;
	}

	/**
	 * Cast a raw column value to int, keeping NULL as null.
	 *
	 * @param mixed $value Raw column value.
	 * @return int|null
	 */
	public static function int_or_null( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * Cast a raw column value to float, keeping NULL as null.
	 *
	 * @param mixed $value Raw column value.
	 * @return float|null
	 */
	public static function float_or_null( mixed $value ): ?float {
		return null === $value ? null : (float) $value;
	}

	/**
	 * Cast a raw 0/1 column value to bool.
	 *
	 * @param mixed $value Raw column value.
	 * @return bool
	 */
	public static function bool( mixed $value ): bool {
		return (bool) (int) $value;
	}

	/**
	 * Cast a raw column value to string, keeping NULL as null.
	 *
	 * @param mixed $value Raw column value.
	 * @return string|null
	 */
	public static function string_or_null( mixed $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	/**
	 * Parse a Y-m-d column value, or return null if it is empty or not a real date.
	 *
	 * @param mixed $value Raw column value.
	 * @return DateTimeImmutable|null
	 */
	public static function date_or_null( mixed $value ): ?DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );
		$errors = DateTimeImmutable::getLastErrors();

		// Reject impossible dates (2024-02-31, 0000-00-00) that PHP would otherwise roll over.
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date;
	}

	/**
	 * Convert a raw integer column into a backed enum case, or null for NULL.
	 *
	 * @param string $enum_class Backed enum class name, e.g. TAAS_Match_Status::class.
	 * @param mixed  $value      Raw column value.
	 * @return BackedEnum|null
	 * @throws UnexpectedValueException If the value is not a case of the enum.
	 */
	public static function enum_or_null( string $enum_class, mixed $value ): ?BackedEnum {
		if ( null === $value ) {
			return null;
		}

		$case = $enum_class::tryFrom( (int) $value );

		if ( null === $case ) {
			throw new UnexpectedValueException( sprintf( 'Invalid %s value: %s.', esc_html( $enum_class ), esc_html( (string) $value ) ) );
		}

		return $case;
	}

	/**
	 * Return a row as an array, whether $wpdb gave us an object or an array.
	 *
	 * @param array|object $row Database row.
	 * @return array<string, mixed>
	 */
	public static function row( array|object $row ): array {
		return (array) $row;
	}
}

/**
 * Constructor-time sanity checks for row values.
 */
final class TAAS_Guard {

	/**
	 * Check that a UFID is 1-8 characters.
	 *
	 * @param string $field Column name, used in the error message.
	 * @param string $value UFID to check.
	 * @throws InvalidArgumentException If the UFID is empty or longer than 8 characters.
	 */
	public static function ufid( string $field, string $value ): void {
		if ( '' === $value || strlen( $value ) > 8 ) {
			throw new InvalidArgumentException( sprintf( '%s must be 1-8 characters.', esc_html( $field ) ) );
		}
	}

	/**
	 * Check that a country code is two letters, or null.
	 *
	 * @param string|null $value ISO 3166-1 alpha-2 code, or null.
	 * @throws InvalidArgumentException If the value is not exactly two letters.
	 */
	public static function country_code( ?string $value ): void {
		if ( null !== $value && 1 !== preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
			throw new InvalidArgumentException( 'country_code must be a two-letter ISO 3166-1 alpha-2 code.' );
		}
	}

	/**
	 * Check that a GPA is within the range decimal(3,2) can hold, or null.
	 *
	 * @param float|null $value GPA, or null. Bounds match decimal(3,2).
	 * @throws InvalidArgumentException If the GPA is outside 0.00-4.00.
	 */
	public static function gpa( ?float $value ): void {
		if ( null !== $value && ( $value < 0.0 || $value > 4.00 ) ) {
			throw new InvalidArgumentException( 'gpa must be between 0.00 and 4.00.' );
		}
	}

	/**
	 * Check that a preference rank is 1 or greater, or null.
	 *
	 * @param string   $field Column name, used in the error message.
	 * @param int|null $value Preference rank, or null.
	 * @throws InvalidArgumentException If the rank is below 1.
	 */
	public static function rank( string $field, ?int $value ): void {
		if ( null !== $value && $value < 1 ) {
			throw new InvalidArgumentException( sprintf( '%s must be 1 or greater.', esc_html( $field ) ) );
		}
	}
}

/*
 * ------------------------------------------------------------------------
 * Row types
 *
 * Each class is immutable. from_row() accepts a row from $wpdb (object or
 * ARRAY_A) and to_row() returns column => value, ready for $wpdb->insert()
 * or $wpdb->update(). Auto-increment ids are null on new, unsaved records,
 * and to_row() omits them in that case.
 * ------------------------------------------------------------------------
 */

/**
 * Row from the professors table.
 */
final class TAAS_Professor {

	/**
	 * Create a professor row.
	 *
	 * @param string $professor_ufid  UFID, 1-8 characters.
	 * @param string $professor_name  Full name.
	 * @param string $professor_email Email address.
	 * @throws InvalidArgumentException If the UFID is invalid.
	 */
	public function __construct(
		public readonly string $professor_ufid,
		public readonly string $professor_name,
		public readonly string $professor_email,
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
			(string) $r['professor_ufid'],
			(string) $r['professor_name'],
			(string) $r['professor_email'],
		);
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'professor_ufid'  => $this->professor_ufid,
			'professor_name'  => $this->professor_name,
			'professor_email' => $this->professor_email,
		);
	}
}

/**
 * Row from the students table.
 */
final class TAAS_Student {

	/**
	 * Create a student row.
	 *
	 * @param string $student_ufid  UFID, 1-8 characters.
	 * @param string $student_name  Full name.
	 * @param string $student_email Email address.
	 * @throws InvalidArgumentException If the UFID is invalid.
	 */
	public function __construct(
		public readonly string $student_ufid,
		public readonly string $student_name,
		public readonly string $student_email,
	) {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		return new self(
			(string) $r['student_ufid'],
			(string) $r['student_name'],
			(string) $r['student_email'],
		);
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'student_ufid'  => $this->student_ufid,
			'student_name'  => $this->student_name,
			'student_email' => $this->student_email,
		);
	}
}

/**
 * Row from the student_profiles table.
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

/**
 * Row from the academic_background table.
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

/**
 * Row from the courses table.
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

/**
 * Row from the applications table.
 */
final class TAAS_Application {

	/**
	 * Create an application row.
	 *
	 * @param int|null          $application_id            Auto-increment id; null on a new, unsaved record.
	 * @param string            $student_ufid              Applicant's UFID, 1-8 characters.
	 * @param string            $applied_course_id         Course section applied for.
	 * @param bool              $has_taken_course          Whether the student has taken the course.
	 * @param string|null       $student_statement         Statement of interest.
	 * @param int|null          $student_preference_rank   Student's ranking of this course, 1 or greater.
	 * @param int|null          $professor_preference_rank Professor's ranking of this student, 1 or greater.
	 * @param TAAS_Match_Status $match_status              Matching outcome.
	 * @throws InvalidArgumentException If the UFID or a rank is invalid.
	 */
	public function __construct(
		public readonly ?int $application_id,
		public readonly string $student_ufid,
		public readonly string $applied_course_id,
		public readonly bool $has_taken_course = false,
		public readonly ?string $student_statement = null,
		public readonly ?int $student_preference_rank = null,
		public readonly ?int $professor_preference_rank = null,
		public readonly TAAS_Match_Status $match_status = TAAS_Match_Status::Pending,
	) {
		TAAS_Guard::ufid( 'student_ufid', $student_ufid );
		TAAS_Guard::rank( 'student_preference_rank', $student_preference_rank );
		TAAS_Guard::rank( 'professor_preference_rank', $professor_preference_rank );
	}

	/**
	 * Build an instance from a database row.
	 *
	 * @param array|object $row Database row.
	 * @throws UnexpectedValueException If match_status is missing or invalid.
	 */
	public static function from_row( array|object $row ): self {
		$r = TAAS_Cast::row( $row );

		$status = TAAS_Cast::enum_or_null( TAAS_Match_Status::class, $r['match_status'] ?? null );

		if ( null === $status ) {
			throw new UnexpectedValueException( 'Invalid applications row: missing match_status.' );
		}

		return new self(
			application_id: TAAS_Cast::int_or_null( $r['application_id'] ?? null ),
			student_ufid: (string) $r['student_ufid'],
			applied_course_id: (string) $r['applied_course_id'],
			has_taken_course: TAAS_Cast::bool( $r['has_taken_course'] ?? 0 ),
			student_statement: TAAS_Cast::string_or_null( $r['student_statement'] ?? null ),
			student_preference_rank: TAAS_Cast::int_or_null( $r['student_preference_rank'] ?? null ),
			professor_preference_rank: TAAS_Cast::int_or_null( $r['professor_preference_rank'] ?? null ),
			match_status: $status,
		);
	}

	/**
	 * Return the row as column => value, ready for $wpdb->insert() or $wpdb->update().
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$row = array(
			'student_ufid'              => $this->student_ufid,
			'applied_course_id'         => $this->applied_course_id,
			'has_taken_course'          => $this->has_taken_course ? 1 : 0,
			'student_statement'         => $this->student_statement,
			'student_preference_rank'   => $this->student_preference_rank,
			'professor_preference_rank' => $this->professor_preference_rank,
			'match_status'              => $this->match_status->value,
		);

		if ( null !== $this->application_id ) {
			$row = array( 'application_id' => $this->application_id ) + $row;
		}

		return $row;
	}
}
