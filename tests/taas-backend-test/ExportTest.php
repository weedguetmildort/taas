<?php
/**
 * Tests for the CSV exports in func-taas-exports.php
 *
 * @package TAAS_Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/htdocs/wp-content/plugins/taas-core/includes/func-taas-exports.php';

/**
 * Check each export's headers, rows and CSV output
 */
class ExportTest extends TestCase {

	private static function course( array $overrides = array() ): array {
		return $overrides + array(
			'course_id'       => '1',
			'course_code'     => 'CEN3031',
			'course_name'     => 'Intro to Software Engineering',
			'course_semester' => '2',
			'course_year'     => '2027',
			'is_online'       => '0',
			'lab_hours'       => '1',
			'enrollment'      => '400',
			'max_enrollment'  => '600',
			'weekly_hours'    => '135',
			'professor_name'  => 'Rani, Neha',
			'professor_email' => 'neharani@ufl.edu',
		);
	}

	private static function application( array $overrides = array() ): array {
		return $overrides + array(
			'course_id'                 => '1',
			'student_ufid'              => '00156789',
			'student_name'              => 'Jay Bardi',
			'student_email'             => 'jay.bard@ufl.edu',
			'student_preference_rank'   => null,
			'professor_preference_rank' => null,
			'match_status'              => '1',
			'assigned_hours'            => '0',
		);
	}

	private static function student( array $overrides = array() ): array {
		return $overrides + array(
			'student_ufid'       => '00156789',
			'student_name'       => 'Jay Bardi',
			'student_email'      => 'jay.bard@ufl.edu',
			'enrollment_status'  => '3',
			'admitted_semester'  => 'Spring 2020',
			'gpa'                => '3.69',
			'english_test_score' => '3',
			'country_code'       => 'IN',
			'available_hours'    => '20',
			'decision'           => 'approve',
		);
	}

	private const COURSE_HEADERS = array( 'Prefix', 'Title', 'Semester', 'Year', 'UFO', 'Lab Hour', 'Current Enrollment', 'Maximum Enrollment', 'Professor Teaching', 'GLID' );

	private const STUDENT_HEADERS = array( 'UFID', 'UF Email', 'Student Name', 'Program', 'Semester Admitted', 'GPA', 'English Test Score', 'Country', 'HR Decision', 'TA Hours' );

	public function test_course_staffing_lists_saved_tas_by_hours(): void {
		$rows = taas_export_course_staffing(
			array( self::course() ),
			array(
				self::application(
					array(
						'match_status'   => '2',
						'assigned_hours' => '10',
					)
				),
				self::application(
					array(
						'student_ufid'   => '00156790',
						'student_name'   => 'Tina Dan',
						'student_email'  => 'tina@ufl.edu',
						'match_status'   => '2',
						'assigned_hours' => '20',
					)
				),
				self::application(
					array(
						'student_ufid'   => '00156791',
						'match_status'   => '1',
						'assigned_hours' => '0',
					)
				),
			)
		);

		$this->assertSame( array_merge( self::COURSE_HEADERS, array( 'Course TA Hours', 'TA Hours Assigned', 'TA Hours Left', 'TA 1 UFID', 'TA 1 Email', 'TA 1 Name', 'TA 1 Hours' ) ), array_slice( $rows[0], 0, 17 ) );
		$this->assertCount( 13 + 5 * 4, $rows[0] );
		$this->assertSame( 'TA 5 Hours', end( $rows[0] ) );
		$this->assertSame(
			array( 'CEN3031', 'Intro to Software Engineering', 'Spring', '2027', 'In-person', '1', '400', '600', 'Rani, Neha', 'neharani@ufl.edu', '135', '30', '105', '00156790', 'tina@ufl.edu', 'Tina Dan', '20', '00156789', 'jay.bard@ufl.edu', 'Jay Bardi', '10', '' ),
			array_slice( $rows[1], 0, 22 )
		);
		$this->assertCount( count( $rows[0] ), $rows[1] );
	}

	public function test_course_staffing_leaves_hours_left_blank_without_course_hours(): void {
		$rows = taas_export_course_staffing(
			array(
				self::course(
					array(
						'weekly_hours' => null,
						'is_online'    => '1',
					)
				),
			),
			array()
		);

		$this->assertSame( array( 'UFO', '', '0', '' ), array( $rows[1][4], $rows[1][10], $rows[1][11], $rows[1][12] ) );
	}

	public function test_match_recommendations_keep_algorithm_order_and_reasons(): void {
		$rows = taas_export_match_recommendations(
			array( self::course() ),
			array(
				array(
					'course_id'         => '1',
					'student_ufid'      => '00156790',
					'student_name'      => 'Tina Dan',
					'student_email'     => 'tina@ufl.edu',
					'recommended_hours' => '20',
					'reason'            => 'professor_preference_1',
				),
				array(
					'course_id'         => '1',
					'student_ufid'      => '00156789',
					'student_name'      => 'Jay Bardi',
					'student_email'     => 'jay.bard@ufl.edu',
					'recommended_hours' => '10',
					'reason'            => 'ta_for_course',
				),
			)
		);

		$this->assertSame( array( 'Course TA Hours', 'TA Hours Recommended', 'TA Hours Left', 'TA 1 UFID', 'TA 1 Email', 'TA 1 Name', 'TA 1 Hours', 'TA 1 Reason' ), array_slice( $rows[0], 10, 8 ) );
		$this->assertCount( 13 + 5 * 5, $rows[0] );
		$this->assertSame(
			array( '135', '30', '105', '00156790', 'tina@ufl.edu', 'Tina Dan', '20', 'Professor preference 1', '00156789', 'jay.bard@ufl.edu', 'Jay Bardi', '10', 'Has TA\'d this course' ),
			array_slice( $rows[1], 10, 13 )
		);
	}

	public function test_reasons_read_as_words(): void {
		$this->assertSame( 'Student preference 3', taas_export_reason( 'student_preference_3' ) );
		$this->assertSame( 'Has taken this course', taas_export_reason( 'taken_course' ) );
		$this->assertSame( 'Has TA experience', taas_export_reason( 'ta_experience' ) );
		$this->assertSame( 'Applied', taas_export_reason( 'applied' ) );
		$this->assertSame( 'something_new', taas_export_reason( 'something_new' ) );
	}

	public function test_ta_assignments_include_unassigned_and_split_applicants(): void {
		$courses = array(
			self::course(),
			self::course(
				array(
					'course_id'      => '2',
					'course_code'    => 'CAP3027',
					'course_name'    => 'Intro Computational Media',
					'is_online'      => '1',
					'professor_name' => 'Zhang, Rong',
				)
			),
		);
		$rows    = taas_export_ta_assignments(
			$courses,
			array(
				self::student(),
				self::student(
					array(
						'student_ufid'       => '00156790',
						'decision'           => null,
						'enrollment_status'  => null,
						'gpa'                => null,
						'english_test_score' => null,
					)
				),
				self::student(
					array(
						'student_ufid'    => '00156791',
						'available_hours' => '20',
					)
				),
			),
			array(
				self::application(
					array(
						'course_id'      => '2',
						'match_status'   => '2',
						'assigned_hours' => '10',
					)
				),
				self::application(
					array(
						'course_id'      => '1',
						'match_status'   => '2',
						'assigned_hours' => '10',
					)
				),
				self::application(
					array(
						'student_ufid'   => '00156791',
						'match_status'   => '2',
						'assigned_hours' => '10',
					)
				),
			)
		);

		$this->assertSame( array_merge( self::STUDENT_HEADERS, array( 'Hours Assigned', 'Assignment Status', 'Course 1', 'Course 1 Hours', 'Course 2', 'Course 2 Hours' ) ), $rows[0] );
		$this->assertSame(
			array( '00156789', 'jay.bard@ufl.edu', 'Jay Bardi', 'PhD', 'Spring 2020', '3.69', '55–60 SPEAK / 28–30 TOEFL iBT', 'IN', 'Approved', '20', '20', 'Assigned', 'CEN3031 - Intro to Software Engineering - Rani, Neha', '10', 'CAP3027 - Intro Computational Media (UFO) - Zhang, Rong', '10' ),
			$rows[1]
		);
		$this->assertSame( array( '', '', '', 'Unreviewed', '0', 'Unassigned', '' ), array( $rows[2][3], $rows[2][5], $rows[2][6], $rows[2][8], $rows[2][10], $rows[2][11], $rows[2][12] ) );
		$this->assertSame( 'Partially Assigned', $rows[3][11] );
	}

	public function test_student_preferences_follow_rank_and_skip_unranked(): void {
		$rows = taas_export_student_preferences(
			array(
				self::course(),
				self::course(
					array(
						'course_id'   => '2',
						'course_code' => 'CAP3027',
						'course_name' => 'Intro Computational Media',
					)
				),
			),
			array( self::student( array( 'decision' => 'reject' ) ) ),
			array(
				self::application(
					array(
						'course_id'               => '1',
						'student_preference_rank' => '2',
					)
				),
				self::application(
					array(
						'course_id'               => '2',
						'student_preference_rank' => '1',
					)
				),
				self::application(
					array(
						'course_id'               => '1',
						'student_preference_rank' => null,
					)
				),
			)
		);

		$this->assertSame( array_merge( self::STUDENT_HEADERS, array( 'Preference 1', 'Preference 2', 'Preference 3', 'Preference 4', 'Preference 5' ) ), $rows[0] );
		$this->assertSame( 'Rejected', $rows[1][8] );
		$this->assertSame(
			array( 'CAP3027 - Intro Computational Media - Rani, Neha', 'CEN3031 - Intro to Software Engineering - Rani, Neha', '', '', '' ),
			array_slice( $rows[1], 10 )
		);
	}

	public function test_instructor_rankings_follow_professor_rank(): void {
		$rows = taas_export_instructor_rankings(
			array(
				self::course(),
				self::course(
					array(
						'course_id'   => '2',
						'course_code' => 'CIS4930',
					)
				),
			),
			array(
				self::application( array( 'professor_preference_rank' => '2' ) ),
				self::application(
					array(
						'student_ufid'              => '00312345',
						'student_email'             => 'tima.miller@ufl.edu',
						'student_name'              => 'Tima Miller',
						'professor_preference_rank' => '1',
					)
				),
				self::application(
					array(
						'student_ufid'              => '00999999',
						'professor_preference_rank' => null,
					)
				),
			)
		);

		$this->assertSame( array_merge( array_values( array_diff( self::COURSE_HEADERS, array( 'Maximum Enrollment' ) ) ), array( 'Pref 1 UFID', 'Pref 1 Email', 'Pref 1 Name' ) ), array_slice( $rows[0], 0, 12 ) );
		$this->assertCount( 9 + 9 * 3, $rows[0] );
		$this->assertSame( array( '00312345', 'tima.miller@ufl.edu', 'Tima Miller', '00156789', 'jay.bard@ufl.edu', 'Jay Bardi', '' ), array_slice( $rows[1], 9, 7 ) );
		$this->assertSame( 'CIS4930', $rows[2][0] );
		$this->assertSame( '', $rows[2][9] );
	}

	public function test_groups_widen_to_the_longest_row(): void {
		$apps = array();

		for ( $i = 1; $i <= 7; $i++ ) {
			$apps[] = self::application(
				array(
					'student_ufid'   => sprintf( '%08d', $i ),
					'match_status'   => '2',
					'assigned_hours' => '5',
				)
			);
		}

		$rows = taas_export_course_staffing( array( self::course(), self::course( array( 'course_id' => '2' ) ) ), $apps );

		$this->assertSame( 'TA 7 Hours', end( $rows[0] ) );
		$this->assertCount( count( $rows[0] ), $rows[2] );
	}

	public function test_empty_term_still_has_headers(): void {
		$rows = taas_export_ta_assignments( array(), array(), array() );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Course 2 Hours', end( $rows[0] ) );
	}

	public function test_csv_has_bom_crlf_and_rfc_4180_quoting(): void {
		$csv = taas_export_csv(
			array(
				array( 'Prefix', 'Professor Teaching' ),
				array( 'CEN3031', 'Rani, Neha' ),
				array( 'CIS4930', 'Says "hi"' ),
			)
		);

		$this->assertSame( "\xEF\xBB\xBFPrefix,\"Professor Teaching\"\r\nCEN3031,\"Rani, Neha\"\r\nCIS4930,\"Says \"\"hi\"\"\"\r\n", $csv );
	}

	public function test_course_export_reads_back_through_course_import_headers(): void {
		require_once dirname( __DIR__, 2 ) . '/htdocs/wp-content/plugins/taas-core/includes/func-taas-course-ingest.php';

		$headers = taas_export_course_staffing( array(), array() )[0];
		$mapped  = array_column( taas_map_headers( $headers ), 'schema_field' );

		$this->assertSame( array( 'course_code', 'course_name', 'course_semester', 'course_year', 'is_online', 'lab_hours', 'enrollment', 'max_enrollment', 'professor_name', 'professor_email' ), $mapped );
	}
}
