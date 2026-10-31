<?php
/**
 * Tests for the TA matching functions in func-matching.php
 *
 * @package TAAS_Core
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/htdocs/wp-content/plugins/taas-core/includes/func-matching.php';

/**
 * Check TA hour calculation and student/course matching
 */
class MatchingTest extends TestCase {

	/**
	 * Professor UFID used on every test course
	 */
	private const PROFESSOR = '99999999';

	/**
	 * Build a course that needs the given number of TA hours
	 *
	 * A 2-hour lab gives 10 TA hours per 25 students, so 2.5 students per hour.
	 *
	 * @param string $course_id    Course section number
	 * @param int    $hours_needed TA hours the course needs, a multiple of 5
	 */
	private function course( string $course_id, int $hours_needed ): TAAS_Course {
		return $this->course_with( $course_id, (int) ( $hours_needed * 2.5 ), 2 );
	}

	/**
	 * Build a course with an explicit enrollment and lab length
	 *
	 * @param string $course_id  Course section number
	 * @param int    $enrollment Enrolled students
	 * @param int    $lab_hours  Lab hours per week
	 */
	private function course_with( string $course_id, int $enrollment, int $lab_hours ): TAAS_Course {
		return new TAAS_Course(
			course_id: $course_id,
			course_code: 'COP1234',
			course_name: 'TestCourse',
			professor_ufid: self::PROFESSOR,
			course_semester: TAAS_Semester::Fall,
			course_year: 2026,
			lab_hours: $lab_hours,
			enrollment: $enrollment,
		);
	}

	/**
	 * Build a student profile
	 *
	 * @param string                      $ufid          Student UFID
	 * @param int|null                    $hours         Weekly hours available
	 * @param TAAS_Enrollment_Status|null $status        Program the student is in, e.g. MS or PhD
	 * @param bool                        $ta_experience Whether the student has been a TA before
	 */
	private function profile( string $ufid, ?int $hours = 20, ?TAAS_Enrollment_Status $status = TAAS_Enrollment_Status::Phd, bool $ta_experience = false ): TAAS_Student_Profile {
		return new TAAS_Student_Profile(
			student_ufid: $ufid,
			available_hours: $hours,
			enrollment_status: $status,
			has_ta_experience: $ta_experience,
		);
	}

	/**
	 * Build an application
	 *
	 * @param string   $ufid         Student UFID
	 * @param string   $course_id    Course section applied for
	 * @param int|null $student_rank Student's rank of the course, null if the student did not rank it
	 * @param int|null $prof_rank    Professor's rank of the student, null if the professor did not rank them
	 * @param bool     $taken        Whether the student has taken the course
	 * @param bool     $ta_for       Whether the student has previously TA'd the course
	 */
	private function app( string $ufid, string $course_id, ?int $student_rank = null, ?int $prof_rank = null, bool $taken = false, bool $ta_for = false ): TAAS_Application {
		return new TAAS_Application(
			application_id: null,
			student_ufid: $ufid,
			applied_course_id: $course_id,
			has_taken_course: $taken,
			has_ta_for_course: $ta_for,
			student_preference_rank: $student_rank,
			professor_preference_rank: $prof_rank,
		);
	}

	/**
	 * Key profiles by UFID, the shape the functions expect
	 *
	 * @param TAAS_Student_Profile ...$profiles Profiles
	 * @return array<string, TAAS_Student_Profile>
	 */
	private function by_ufid( TAAS_Student_Profile ...$profiles ): array {
		$keyed = array();

		foreach ( $profiles as $profile ) {
			$keyed[ $profile->student_ufid ] = $profile;
		}

		return $keyed;
	}

	/**
	 * Return the matched UFIDs as strings, in match order
	 *
	 * Matches are keyed by UFID, but PHP turns all-digit keys into ints,
	 * so read the UFID from each match instead.
	 *
	 * @param array $matches Matches from a matching function
	 * @return string[]
	 */
	private function ufids( array $matches ): array {
		return array_values( array_map( 'strval', array_column( $matches, 'student_ufid' ) ) );
	}

	/**
	 * Run taas_match_course() and return the matches
	 *
	 * @param TAAS_Course            $course   Course to staff
	 * @param TAAS_Application[]     $apps     Applications for the course
	 * @param TAAS_Student_Profile[] $profiles Applicant profiles
	 * @param int                    $cth      Hours to fill
	 * @param array<string, string>  $assigned Existing assignments
	 * @return array Matches keyed by UFID
	 */
	private function match_course( TAAS_Course $course, array $apps, array $profiles, int $cth, array $assigned = array() ): array {
		return taas_match_course( $course, $apps, $this->by_ufid( ...$profiles ), $assigned, $cth );
	}

	// ---------------------------------------------------------------------
	// taas_calc_course_ta_hours()
	// ---------------------------------------------------------------------

	/**
	 * Test course calculations
	 */
	public function test_hours_formula_and_rounding(): void {
		$this->assertSame( 20, taas_calc_course_ta_hours( $this->course_with( '1', 70, 0 ) ) );
		$this->assertSame( 20, taas_calc_course_ta_hours( $this->course_with( '1', 60, 1 ) ) );
		$this->assertSame( 20, taas_calc_course_ta_hours( $this->course_with( '1', 50, 2 ) ) );
		$this->assertSame( 30, taas_calc_course_ta_hours( $this->course_with( '1', 100, 0 ) ) ); // 28.57 -> 30
		$this->assertSame( 10, taas_calc_course_ta_hours( $this->course_with( '1', 31, 2 ) ) );  // 12.4  -> 10
		$this->assertSame( 5, taas_calc_course_ta_hours( $this->course_with( '1', 10, 0 ) ) );   // 2.86  -> 5
	}

	/**
	 * Enrollment under 10 and lab lengths other than 0, 1, or 2 are rejected
	 */
	public function test_hours_reject_invalid_course(): void {
		foreach ( array( array( 9, 0 ), array( -10, 0 ), array( 50, 3 ) ) as [ $enrollment, $lab_hours ] ) {
			try {
				taas_calc_course_ta_hours( $this->course_with( '1', $enrollment, $lab_hours ) );
				$this->fail( "No exception for enrollment $enrollment, lab $lab_hours" );
			} catch ( InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	// ---------------------------------------------------------------------
	// taas_match_candidates()
	// ---------------------------------------------------------------------

	/**
	 * Test matching candidates of a mix of assigned status/various hours, including edge cases
	 */
	public function test_candidates_skip_unavailable_and_update_state(): void {
		$assigned = array( 'TAKEN' => 'OTHER' );
		$cth      = 40;

		$matches = taas_match_candidates(
			$this->course( 'A', 40 ),
			array( 'TAKEN', 'NOHOURS', 'ZERO', 'OVER', 'FIT20', 'FIT15', 'SMALL' ),
			'test_reason',
			$this->by_ufid(
				$this->profile( 'TAKEN' ),
				$this->profile( 'NOHOURS', hours: null ),
				$this->profile( 'ZERO', hours: 0 ),
				$this->profile( 'OVER', hours: 50 ),
				$this->profile( 'FIT20', hours: 20 ),
				$this->profile( 'FIT15', hours: 15 ),
				$this->profile( 'SMALL', hours: 2 ), // Would fit, but only 5 hours are left
			),
			$assigned,
			$cth
		);

		$this->assertSame( array( 'FIT20', 'FIT15' ), $this->ufids( $matches ) );
		$this->assertSame(
			array(
				'student_ufid' => 'FIT20',
				'hours'        => 20,
				'reason'       => 'test_reason',
			),
			$matches['FIT20']
		);
		$this->assertSame(
			array(
				'TAKEN' => 'OTHER',
				'FIT20' => 'A',
				'FIT15' => 'A',
			),
			$assigned
		);
		$this->assertSame( 5, $cth );
	}

	/**
	 * A student whose hours exactly match the hours left is matched, filling the course
	 */
	public function test_candidates_exact_fit_matched(): void {
		$assigned = array();
		$cth      = 20;

		$matches = taas_match_candidates(
			$this->course( 'A', 20 ),
			array( 'EXACT' ),
			'test_reason',
			$this->by_ufid( $this->profile( 'EXACT', hours: 20 ) ),
			$assigned,
			$cth
		);

		$this->assertSame( array( 'EXACT' ), $this->ufids( $matches ) );
		$this->assertSame( 0, $cth );
	}

	// ---------------------------------------------------------------------
	// taas_match_course()
	// ---------------------------------------------------------------------

	/**
	 * Test the priorities of all tiers
	 */
	public function test_full_priority_order(): void {
		$assigned = array();
		$cth      = 70;

		$matches = taas_match_course(
			$this->course( 'A', 70 ),
			array(
				$this->app( 'APPLIED', 'A' ),
				$this->app( 'TA', 'A' ),
				$this->app( 'TAKEN', 'A', taken: true ),
				$this->app( 'TAFOR', 'A', ta_for: true ),
				$this->app( 'STUDENT', 'A', student_rank: 1 ),
				$this->app( 'MUTUAL', 'A', student_rank: 5, prof_rank: 1 ),
			),
			$this->by_ufid(
				$this->profile( 'APPLIED', hours: 10 ),
				$this->profile( 'TA', hours: 10, ta_experience: true ),
				$this->profile( 'TAKEN', hours: 10 ),
				$this->profile( 'TAFOR', hours: 10 ),
				$this->profile( 'STUDENT', hours: 10 ),
				$this->profile( 'MUTUAL', hours: 10 ),
			),
			$assigned,
			$cth
		);

		$this->assertSame( array( 'MUTUAL', 'STUDENT', 'TAFOR', 'TAKEN', 'TA', 'APPLIED' ), $this->ufids( $matches ) );
		$this->assertSame(
			array( 'professor_preference_1', 'student_preference_1', 'ta_for_course', 'taken_course', 'ta_experience', 'applied' ),
			array_column( $matches, 'reason' )
		);
		$this->assertSame( 10, $cth );
	}

	/**
	 * Test when an instructor's pick is unavaliable
	 */
	public function test_instructor_pick_skipped_when_unavailable_or_one_sided(): void {
		$matches = $this->match_course(
			$this->course( 'A', 50 ),
			array(
				$this->app( 'UNAVAIL', 'A', student_rank: 1, prof_rank: 1 ),
				$this->app( 'ONESIDED', 'A', prof_rank: 2 ),
				$this->app( 'MUTUAL', 'A', student_rank: 2, prof_rank: 3 ),
			),
			array(
				$this->profile( 'UNAVAIL', hours: 10 ),
				$this->profile( 'ONESIDED', hours: 10 ),
				$this->profile( 'MUTUAL', hours: 10 ),
			),
			50,
			array( 'UNAVAIL' => 'OTHER' )
		);

		$this->assertSame( array( 'MUTUAL', 'ONESIDED' ), $this->ufids( $matches ) );
		$this->assertSame( array( 'professor_preference_3', 'applied' ), array_column( $matches, 'reason' ) );
	}

	/**
	 * Within tiers 1 and 2, ties on rank are broken by UFID (alphanumeric), and rank still comes before UFID
	 */
	public function test_preference_ties_broken_by_ufid(): void {
		$matches = $this->match_course(
			$this->course( 'A', 100 ),
			array(
				$this->app( '30000000', 'A', student_rank: 1, prof_rank: 1 ),
				$this->app( '00000001', 'A', student_rank: 1, prof_rank: 2 ),
				$this->app( '10000000', 'A', student_rank: 1, prof_rank: 1 ),
				$this->app( 'ZED', 'A', student_rank: 1 ),
				$this->app( 'ABE', 'A', student_rank: 2 ),
				$this->app( 'MAX', 'A', student_rank: 1 ),
			),
			array(
				$this->profile( '30000000', hours: 10 ),
				$this->profile( '00000001', hours: 10 ),
				$this->profile( '10000000', hours: 10 ),
				$this->profile( 'ZED', hours: 10 ),
				$this->profile( 'ABE', hours: 10 ),
				$this->profile( 'MAX', hours: 10 ),
			),
			100
		);

		$this->assertSame( array( '10000000', '30000000', '00000001', 'MAX', 'ZED', 'ABE' ), $this->ufids( $matches ) );
		$this->assertSame(
			array( 'professor_preference_1', 'professor_preference_1', 'professor_preference_2', 'student_preference_1', 'student_preference_1', 'student_preference_2' ),
			array_column( $matches, 'reason' )
		);
	}

	// ---------------------------------------------------------------------
	// taas_run_matching()
	// ---------------------------------------------------------------------

	/**
	 * Every PhD student applies to every course, so tiers 5 and 6 place PhD students
	 * in courses they did not rank
	 */
	public function test_phd_priority(): void {
		// Course ID => UFID => [student rank, professor rank]; every other application is unranked
		$ranks = array(
			'B' => array(
				'PHD1' => array( 1, 1 ),
				'PHD2' => array( 1, 2 ),
			),
		);

		$apps = array();
		foreach ( array( 'A', 'B', 'C' ) as $course_id ) {
			foreach ( array( 'PHD1', 'PHD2', 'PHDNEW', 'PHDTA' ) as $ufid ) {
				[ $student_rank, $prof_rank ] = $ranks[ $course_id ][ $ufid ] ?? array( null, null );
				$apps[]                       = $this->app( $ufid, $course_id, student_rank: $student_rank, prof_rank: $prof_rank );
			}
		}

		$result = taas_run_matching(
			array( $this->course( 'A', 30 ), $this->course( 'B', 30 ), $this->course( 'C', 30 ) ),
			$apps,
			array(
				$this->profile( 'PHD1', status: TAAS_Enrollment_Status::Phd ),
				$this->profile( 'PHD2', status: TAAS_Enrollment_Status::Phd ),
				$this->profile( 'PHDNEW', status: TAAS_Enrollment_Status::Phd ),
				$this->profile( 'PHDTA', status: TAAS_Enrollment_Status::Phd, ta_experience: true ),
			)
		);

		$this->assertSame( array( 'PHD1' ), $this->ufids( $result['matches']['B'] ) );
		$this->assertSame( 'professor_preference_1', $result['matches']['B']['PHD1']['reason'] );
		$this->assertSame( array( 'PHDTA' ), $this->ufids( $result['matches']['A'] ) );
		$this->assertSame( 'ta_experience', $result['matches']['A']['PHDTA']['reason'] );
		$this->assertSame( array( 'PHD2' ), $this->ufids( $result['matches']['C'] ) );
		$this->assertSame( 'applied', $result['matches']['C']['PHD2']['reason'] );
	}

	/**
	 * Every course appears in the result, with the hours it still needs after matching
	 */
	public function test_unfilled_hours_reported_for_every_course(): void {
		$result = taas_run_matching(
			array( $this->course( 'A', 40 ), $this->course( 'B', 20 ), $this->course( 'C', 20 ) ),
			array(
				$this->app( 'S1', 'A', student_rank: 1 ),
				$this->app( 'S2', 'B', student_rank: 1 ),
			),
			array(
				$this->profile( 'S1', hours: 10, status: TAAS_Enrollment_Status::Phd ),
				$this->profile( 'S2', hours: 10, status: TAAS_Enrollment_Status::Phd ),
			)
		);

		$this->assertSame(
			array(
				'A' => 30,
				'B' => 10,
				'C' => 20,
			),
			$result['unfilled_hours']
		);
		$this->assertSame( array( 'A', 'B', 'C' ), array_keys( $result['matches'] ) );
		$this->assertSame( array(), $result['matches']['C'] );
	}
}
