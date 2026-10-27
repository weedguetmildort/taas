<?php
/**
 * Tests for the row types, enums and import helpers touched by the schema
 *
 * @package TAAS_Core
 */

use PHPUnit\Framework\TestCase;

// Plain functions are not covered by the Composer classmap
require_once dirname( __DIR__, 2 ) . '/htdocs/wp-content/plugins/taas-core/includes/func-taas-csv-ingest.php';

/**
 * Check that rows round-trip through the new columns and invalid values are refused
 */
class TypesTest extends TestCase {

	/**
	 * Every new student_profiles column hydrates to its native type
	 */
	public function test_student_profile_hydrates_new_columns(): void {
		$profile = TAAS_Student_Profile::from_row(
			array(
				'student_ufid'      => '12345678',
				'major'             => 'Computer Science, Business',
				'gpa'               => '3.50',
				'enrollment_status' => '4',
				'toefl_ibt_score'   => '108',
				'toefl_ibt_date'    => '2025-09-13',
				'has_ta_experience' => '0',
			)
		);

		$this->assertSame( 'Computer Science, Business', $profile->major );
		$this->assertSame( 3.5, $profile->gpa );
		$this->assertSame( TAAS_Enrollment_Status::Phd, $profile->enrollment_status );
		$this->assertSame( 108, $profile->toefl_ibt_score );
		$this->assertSame( '2025-09-13', $profile->toefl_ibt_date->format( 'Y-m-d' ) );
	}

	/**
	 * Zero means "not enrolled", which is not the same as "not provided"
	 */
	public function test_enrollment_status_zero_is_not_enrolled_not_null(): void {
		$enrolled = TAAS_Student_Profile::from_row(
			array(
				'student_ufid'      => '12345678',
				'enrollment_status' => '0',
			)
		);
		$unknown  = TAAS_Student_Profile::from_row( array( 'student_ufid' => '12345678' ) );

		$this->assertSame( TAAS_Enrollment_Status::Not_Enrolled, $enrolled->enrollment_status );
		$this->assertSame( 0, $enrolled->to_row()['enrollment_status'] );
		$this->assertNull( $unknown->enrollment_status );
		$this->assertNull( $unknown->to_row()['enrollment_status'] );
	}

	/**
	 * The profile's to_row() writes exactly the student_profiles columns, with no removed ones left over
	 */
	public function test_student_profile_to_row_matches_schema_columns(): void {
		$row = ( new TAAS_Student_Profile(
			student_ufid: '12345678',
			toefl_ibt_date: new DateTimeImmutable( '2025-09-13' ),
		) )->to_row();

		$this->assertSame(
			array(
				'student_ufid',
				'phone',
				'admitted_semester',
				'available_hours',
				'major',
				'gpa',
				'enrollment_status',
				'resume_url',
				'toefl_ibt_score',
				'toefl_ibt_date',
				'has_ta_experience',
				'eap5836_status',
				'eap5837_status',
				'country_code',
				'additional_info',
			),
			array_keys( $row )
		);
		$this->assertSame( '2025-09-13', $row['toefl_ibt_date'] );
	}

	/**
	 * Profile GPA follows the same 0.00-4.00 rule as academic_background
	 */
	public function test_student_profile_rejects_out_of_range_gpa(): void {
		$this->expectException( InvalidArgumentException::class );
		new TAAS_Student_Profile( student_ufid: '12345678', gpa: 4.5 );
	}

	/**
	 * A stored enrollment_status outside the enum is an error, not a silent null
	 */
	public function test_student_profile_rejects_unknown_enrollment_status(): void {
		$this->expectException( UnexpectedValueException::class );
		TAAS_Student_Profile::from_row(
			array(
				'student_ufid'      => '12345678',
				'enrollment_status' => '9',
			)
		);
	}

	/**
	 * The course's to_row() writes exactly the courses columns, with no removed ones left over
	 */
	public function test_course_to_row_matches_schema_columns(): void {
		$row = ( new TAAS_Course( '12345', 'COP3502', 'Programming 1', '87654321', TAAS_Semester::Summer_C, 2027, true ) )->to_row();

		$this->assertSame(
			array(
				'course_id',
				'course_code',
				'course_name',
				'course_semester',
				'course_year',
				'is_online',
				'course_description',
				'course_notes',
				'sections',
				'weekly_hours',
				'lab_hours',
				'enrollment',
				'max_enrollment',
				'professor_ufid',
			),
			array_keys( $row )
		);
		$this->assertSame( 5, $row['course_semester'] );
		$this->assertSame( 2027, $row['course_year'] );
		$this->assertSame( 1, $row['is_online'] );
	}

	/**
	 * The application's to_row() writes exactly the applications columns, in schema order
	 */
	public function test_application_to_row_matches_schema_columns(): void {
		$row = ( new TAAS_Application( 7, '12345678', 'COP3502-1234', has_ta_for_course: true ) )->to_row();

		$this->assertSame(
			array(
				'application_id',
				'student_ufid',
				'applied_course_id',
				'has_taken_course',
				'has_ta_for_course',
				'student_statement',
				'student_preference_rank',
				'professor_preference_rank',
				'match_status',
			),
			array_keys( $row )
		);
		$this->assertSame( 0, $row['has_taken_course'] );
		$this->assertSame( 1, $row['has_ta_for_course'] );
	}

	/**
	 * has_ta_for_course hydrates independently of has_taken_course, and defaults to false when absent
	 */
	public function test_application_hydrates_has_ta_for_course(): void {
		$base = array(
			'student_ufid'      => '12345678',
			'applied_course_id' => 'COP3502-1234',
			'match_status'      => '1',
		);

		$ta_before = TAAS_Application::from_row(
			$base + array(
				'has_taken_course'  => '0',
				'has_ta_for_course' => '1',
			)
		);
		$legacy    = TAAS_Application::from_row( $base );

		$this->assertFalse( $ta_before->has_taken_course );
		$this->assertTrue( $ta_before->has_ta_for_course );
		$this->assertFalse( $legacy->has_ta_for_course );
		$this->assertSame( 0, $legacy->to_row()['has_ta_for_course'] );
	}

	/**
	 * The course_semester column is NOT NULL, so a row without it is corrupt
	 */
	public function test_course_from_row_requires_semester(): void {
		$this->expectException( UnexpectedValueException::class );
		TAAS_Course::from_row(
			array(
				'course_id'      => '12345',
				'course_code'    => 'COP3502',
				'course_name'    => 'Programming 1',
				'course_year'    => '2026',
				'professor_ufid' => '87654321',
			)
		);
	}

	/**
	 * The course_year column must fit MySQL's YEAR type
	 */
	public function test_course_rejects_year_outside_mysql_range(): void {
		$this->expectException( InvalidArgumentException::class );
		new TAAS_Course( '12345', 'COP3502', 'Programming 1', '87654321', TAAS_Semester::Fall, 26 );
	}

	/**
	 * Semester text from the import prompt maps to a case regardless of spacing or case
	 */
	public function test_semester_try_from_label(): void {
		$this->assertSame( TAAS_Semester::Fall, TAAS_Semester::try_from_label( 'fall' ) );
		$this->assertSame( TAAS_Semester::Summer_A, TAAS_Semester::try_from_label( 'Summer A' ) );
		$this->assertSame( TAAS_Semester::Summer_B, TAAS_Semester::try_from_label( 'summerb' ) );
		$this->assertSame( TAAS_Semester::Summer_C, TAAS_Semester::try_from_label( 'SUMMER_C' ) );
		$this->assertNull( TAAS_Semester::try_from_label( 'Winter' ) );
		$this->assertNull( TAAS_Semester::try_from_label( 'Summer' ) );
	}

	/**
	 * The spreadsheet's UFO column becomes is_online
	 */
	public function test_parse_online_flag(): void {
		$this->assertTrue( taas_parse_online_flag( 'Y' ) );
		$this->assertTrue( taas_parse_online_flag( 'yes' ) );
		$this->assertTrue( taas_parse_online_flag( 'UFO' ) );
		$this->assertFalse( taas_parse_online_flag( '' ) );
		$this->assertFalse( taas_parse_online_flag( 'n' ) );
		$this->assertFalse( taas_parse_online_flag( '0' ) );
	}

	/**
	 * An unrecognised UFO value stops the import rather than guessing
	 */
	public function test_parse_online_flag_rejects_unknown_value(): void {
		$this->expectException( RuntimeException::class );
		taas_parse_online_flag( 'maybe' );
	}
}
