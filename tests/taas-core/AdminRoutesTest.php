<?php
/**
 * Tests for the rules in the admin course and application routes
 *
 * @package TAAS_Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment, WordPress.Security.NonceVerification

use PHPUnit\Framework\TestCase;

if ( ! defined( 'TAAS_LOCAL_DEV' ) ) {
	define( 'TAAS_LOCAL_DEV', true );
}

require_once dirname( __DIR__ ) . '/support/class-wp-error.php';
require_once dirname( __DIR__ ) . '/support/class-wp-rest-controller.php';
require_once dirname( __DIR__ ) . '/support/class-wp-rest-request.php';
require_once dirname( __DIR__ ) . '/support/class-wp-rest-response.php';
require_once dirname( __DIR__ ) . '/support/class-taas-fake-wpdb.php';

// Plain functions are not covered by the Composer classmap
require_once dirname( __DIR__, 2 ) . '/htdocs/wp-content/plugins/taas-core/includes/func-matching.php';

/**
 * Check what routes accept, refuse and send back
 */
class AdminRoutesTest extends TestCase {

	/**
	 * Original global, restored after each test
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Fake database for the test
	 *
	 * @var TAAS_Fake_Wpdb
	 */
	private TAAS_Fake_Wpdb $db;

	protected function setUp(): void {
		global $wpdb;

		$this->original_wpdb = $wpdb ?? null;
		$this->db            = new TAAS_Fake_Wpdb();
		$wpdb                = $this->db;
		$_GET['taas_role']   = 'admin';
	}

	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;
		unset( $_GET['taas_role'] );
	}

	private static function application_row( int $status = 1 ): array {
		return array(
			'application_id'    => '7',
			'student_ufid'      => '12345678',
			'applied_course_id' => '101',
			'has_taken_course'  => '1',
			'has_ta_for_course' => '0',
			'match_status'      => (string) $status,
			'assigned_hours'    => '0',
		);
	}

	private function patch_application( array $params ) {
		return ( new TAAS_REST_Application_Controller() )->update_item( new WP_REST_Request( $params, array( 'id' => '7' ) ) );
	}

	public function test_weekly_hours_follow_the_formula(): void {
		$this->assertSame( 70, taas_calc_weekly_hours( 175, 2 ) );
		$this->assertSame( 15, taas_calc_weekly_hours( 60, 0 ) );
		$this->assertSame( 45, taas_calc_weekly_hours( 140, 1 ) );
		$this->assertSame( 5, taas_calc_weekly_hours( 10, 0 ) );
	}

	public function test_weekly_hours_are_null_under_10_students_or_with_unknown_labs(): void {
		$this->assertNull( taas_calc_weekly_hours( 9, 0 ) );
		$this->assertNull( taas_calc_weekly_hours( 100, 3 ) );
	}

	public function test_lab_hours_accepts_only_0_1_or_2(): void {
		$controller = new TAAS_REST_Course_Controller();

		foreach ( array( 0, 1, 2, '2' ) as $valid ) {
			$this->assertTrue( $controller->validate_lab_hours( $valid ) );
		}

		foreach ( array( 3, -1, 1.5, 'two', true ) as $invalid ) {
			$this->assertSame( 'rest_invalid_param', $controller->validate_lab_hours( $invalid )->get_error_code() );
		}
	}

	public function test_sending_only_a_term_or_only_a_year_is_400(): void {
		$controller = new TAAS_REST_Course_Controller();

		foreach ( array( array( 'term' => 'Fall' ), array( 'year' => 2027 ) ) as $params ) {
			$result = $controller->get_items(
				new WP_REST_Request(
					$params + array(
						'page'     => 1,
						'per_page' => 50,
					)
				)
			);

			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	public function test_listing_courses_with_no_term_and_no_cycle_is_404(): void {
		$result = ( new TAAS_REST_Course_Controller() )->get_items(
			new WP_REST_Request(
				array(
					'page'     => 1,
					'per_page' => 50,
				)
			)
		);

		$this->assertSame( 'taas_no_current_cycle', $result->get_error_code() );
	}

	public function test_courses_can_be_filtered_by_professor(): void {
		$_GET['taas_role'] = 'student';

		( new TAAS_REST_Course_Controller() )->get_items(
			new WP_REST_Request(
				array(
					'term'         => 'Fall',
					'year'         => 2027,
					'page'         => 1,
					'per_page'     => 50,
					'professor_id' => 3,
				)
			)
		);

		$this->assertStringContainsString( 'c.professor_id = 3', $this->db->sql );
	}

	public function test_adding_a_course_that_loses_a_race_to_the_unique_key_is_409(): void {
		$this->db->row           = array(
			'professor_id'    => '3',
			'professor_ufid'  => null,
			'professor_name'  => 'Grace Hopper',
			'professor_email' => 'grace@ufl.edu',
		);
		$this->db->insert_result = false;
		$this->db->insert_error  = "Duplicate entry '2-2027-COP3502C-Programming Fundamentals 1-0' for key 'course_offering_by_delivery'";

		$result = ( new TAAS_REST_Course_Controller() )->create_item(
			new WP_REST_Request(
				array(
					'course_code'  => 'COP3502C',
					'course_name'  => 'Programming Fundamentals 1',
					'term'         => 'Spring',
					'year'         => 2027,
					'professor_id' => 3,
				)
			)
		);

		$this->assertSame( 'taas_course_exists', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_adding_a_course_needs_an_existing_professor(): void {
		$result = ( new TAAS_REST_Course_Controller() )->create_item(
			new WP_REST_Request(
				array(
					'course_code'  => 'COP3502C',
					'course_name'  => 'Programming Fundamentals 1',
					'term'         => 'Spring',
					'year'         => 2027,
					'professor_id' => 3,
				)
			)
		);

		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertArrayHasKey( 'professor_id', $result->get_error_data()['params'] );
	}

	public function test_setting_matched_needs_at_least_1_hour(): void {
		$this->db->row = self::application_row();

		$this->assertSame( 'rest_missing_callback_param', $this->patch_application( array( 'match_status' => 'Matched' ) )->get_error_code() );

		$result = $this->patch_application(
			array(
				'match_status'   => 'Matched',
				'assigned_hours' => 0,
			)
		);

		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_setting_matched_saves_the_hours_and_sends_labels(): void {
		$this->db->row = self::application_row();

		$result = $this->patch_application(
			array(
				'match_status'   => 'Matched',
				'assigned_hours' => 20,
			)
		);

		$this->assertSame(
			array( 'UPDATE `wp_taas_core_applications` SET match_status = 2, assigned_hours = 20 WHERE application_id = 7 AND match_status <> 4' ),
			$this->db->queries
		);
		$this->assertSame( 'Pending', $result->get_data()['match_status'] ); // The fake row does not change.
		$this->assertTrue( $result->get_data()['has_taken_course'] );
		$this->assertFalse( $result->get_data()['has_ta_for_course'] );
	}

	public function test_pending_and_rejected_reset_the_hours(): void {
		$this->db->row = self::application_row( 2 );

		foreach ( array(
			'Pending'  => 1,
			'Rejected' => 3,
		) as $label => $value ) {
			$this->patch_application(
				array(
					'match_status'   => $label,
					'assigned_hours' => 20,
				)
			);

			$this->assertStringContainsString( "SET match_status = {$value}, assigned_hours = 0 ", end( $this->db->queries ) );
		}
	}

	public function test_withdrawn_applications_cannot_be_changed(): void {
		$this->db->row = self::application_row( 4 );

		$result = $this->patch_application( array( 'match_status' => 'Pending' ) );

		$this->assertSame( 'taas_application_withdrawn', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_unknown_application_is_404(): void {
		$this->assertSame( 'taas_not_found', $this->patch_application( array( 'match_status' => 'Pending' ) )->get_error_code() );
	}

	public function test_the_admin_cannot_set_withdrawn(): void {
		$controller = new TAAS_REST_Application_Controller();

		$this->assertTrue( $controller->validate_match_status( 'Matched' ) );
		$this->assertSame( 'rest_invalid_param', $controller->validate_match_status( 'Withdrawn' )->get_error_code() );
		$this->assertSame( 'rest_invalid_param', $controller->validate_match_status( 2 )->get_error_code() );
	}

	/**
	 * Open applications cannot be rejected by accident
	 *
	 * @dataProvider stages_that_block_finalizing
	 */
	public function test_finalize_is_refused_outside_admin_matching( array $dates ): void {
		$this->db->row = $dates + array(
			'cycle_semester' => '2',
			'cycle_year'     => '2027',
		);

		$result = ( new TAAS_REST_Matching_Controller() )->finalize( new WP_REST_Request() );

		$this->assertSame( 'taas_matching_not_open', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->db->queries );
	}

	public static function stages_that_block_finalizing(): array {
		return array(
			'student_application' => array( array( 'student_application_start_at' => '2020-01-01 00:00:00' ) ),
			'hr_review'           => array( array( 'hr_review_start_at' => '2020-01-01 00:00:00' ) ),
			'professor_ranking'   => array( array( 'professor_ranking_start_at' => '2020-01-01 00:00:00' ) ),
			'no active stage'     => array( array() ),
			'matching has ended'  => array(
				array(
					'admin_matching_start_at' => '2020-01-01 00:00:00',
					'admin_matching_end_at'   => '2020-02-01 00:00:00',
				),
			),
		);
	}

	public function test_finalize_is_refused_for_any_other_term(): void {
		$this->db->row = array(
			'cycle_semester'          => '2',
			'cycle_year'              => '2027',
			'admin_matching_start_at' => '2020-01-01 00:00:00',
		);

		$request = new WP_REST_Request(
			array(
				'term' => 'Fall',
				'year' => 2026,
			)
		);

		$this->assertSame( 'taas_matching_not_open', ( new TAAS_REST_Matching_Controller() )->finalize( $request )->get_error_code() );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_finalize_is_refused_when_no_cycle_exists(): void {
		$request = new WP_REST_Request(
			array(
				'term' => 'Spring',
				'year' => 2027,
			)
		);

		$this->assertSame( 'taas_matching_not_open', ( new TAAS_REST_Matching_Controller() )->finalize( $request )->get_error_code() );
	}

	public function test_editing_a_course_leaves_its_notes_alone(): void {
		$this->db->row = array(
			'course_id'       => '101',
			'course_code'     => 'COP3502',
			'course_name'     => 'Programming 1',
			'course_semester' => '2',
			'course_year'     => '2027',
			'course_notes'    => 'Written by the professor',
			'professor_id'    => '3',
			'professor_name'  => 'Dr. Smith',
			'professor_email' => 'smith@ufl.edu',
		);

		( new TAAS_REST_Course_Controller() )->update_item( new WP_REST_Request( array( 'enrollment' => 175 ), array( 'id' => '101' ) ) );

		$this->assertSame( 175, $this->db->write['data']['enrollment'] );
		$this->assertArrayNotHasKey( 'course_notes', $this->db->write['data'] );
		$this->assertArrayNotHasKey( 'course_id', $this->db->write['data'] );
	}

	public function test_a_stored_row_that_fails_its_checks_is_a_clean_500(): void {
		$this->db->rows = array(
			array(
				'course_id'       => '101',
				'course_code'     => 'COP3502',
				'course_name'     => 'Programming 1',
				'course_semester' => '2',
				'course_year'     => '2027',
				'professor_id'    => '0',
				'professor_name'  => 'Dr. Smith',
				'professor_email' => 'smith@ufl.edu',
			),
		);

		$result = ( new TAAS_REST_Course_Controller() )->get_item( new WP_REST_Request( array(), array( 'id' => '101' ) ) );

		$this->assertSame( 'taas_server_error', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_only_a_named_student_narrows_the_term_query(): void {
		$repository = new TAAS_Application_Repository();

		$repository->students_for_term( TAAS_Semester::Spring, 2027 );
		$this->assertStringNotContainsString( 'a.student_ufid =', $this->db->sql );
		$this->assertStringContainsString( 'LIMIT 50 OFFSET 0', $this->db->sql );

		$repository->students_for_term( TAAS_Semester::Spring, 2027, '12345678' );
		$this->assertStringContainsString( "a.student_ufid = '12345678'", $this->db->sql );
		$this->assertStringContainsString( 'LIMIT 50 OFFSET 0', $this->db->sql );
	}

	public function test_finalize_runs_during_admin_matching(): void {
		$this->db->row = array(
			'cycle_semester'          => '2',
			'cycle_year'              => '2027',
			'admin_matching_start_at' => '2020-01-01 00:00:00',
		);

		$data = ( new TAAS_REST_Matching_Controller() )->finalize( new WP_REST_Request() )->get_data();

		$this->assertSame( 'Spring', $data['term'] );
		$this->assertSame( 2027, $data['year'] );
		$this->assertStringContainsString( 'UPDATE', $this->db->queries[0] );
		$this->assertStringContainsString( 'a.match_status = 1', $this->db->queries[0] );
	}

	public function test_course_recommendations_come_from_the_table_in_saved_order(): void {
		$this->db->row  = array(
			'course_id'       => '101',
			'course_code'     => 'COP3502C',
			'course_name'     => 'Programming Fundamentals 1',
			'professor_id'    => '1',
			'course_semester' => '2',
			'course_year'     => '2027',
			'weekly_hours'    => '50',
		);
		$this->db->rows = array(
			array(
				'application_id'    => '7',
				'course_id'         => '101',
				'student_ufid'      => '12345678',
				'student_name'      => 'Jane Doe',
				'recommended_hours' => '20',
				'reason'            => 'professor_preference_1',
			),
			array(
				'application_id'    => '9',
				'course_id'         => '101',
				'student_ufid'      => '23456789',
				'student_name'      => 'Sam Lee',
				'recommended_hours' => '10',
				'reason'            => 'applied',
			),
		);

		$data = ( new TAAS_REST_Matching_Controller() )->get_course_recommendations( new WP_REST_Request( array(), array( 'course_id' => '101' ) ) )->get_data();

		$this->assertSame( 101, $data['course_id'] );
		$this->assertSame( 50, $data['weekly_hours'] );
		$this->assertSame( 20, $data['unfilled_hours'] );
		$this->assertSame(
			array(
				array(
					'application_id' => 7,
					'student_ufid'   => '12345678',
					'student_name'   => 'Jane Doe',
					'hours'          => 20,
					'reason'         => 'professor_preference_1',
				),
				array(
					'application_id' => 9,
					'student_ufid'   => '23456789',
					'student_name'   => 'Sam Lee',
					'hours'          => 10,
					'reason'         => 'applied',
				),
			),
			$data['recommendations']
		);
		$this->assertStringContainsString( 'WHERE r.course_id IN (101)', $this->db->sql );
		$this->assertStringContainsString( 'ORDER BY r.recommendation_id', $this->db->sql );
	}

	/**
	 * The fake returns the same rows for every read, so one row stands in for the
	 * course, the application and the applicant's profile
	 */
	private function run_matching_with_enrollment( int $enrollment ) {
		$this->db->insert_id = 1;
		$this->db->rows      = array(
			array(
				'course_id'               => '101',
				'course_code'             => 'COP3502C',
				'course_name'             => 'Programming Fundamentals 1',
				'professor_id'            => '1',
				'course_semester'         => '2',
				'course_year'             => '2027',
				'enrollment'              => (string) $enrollment,
				'lab_hours'               => '2',
				'application_id'          => '7',
				'student_ufid'            => '12345678',
				'applied_course_id'       => '101',
				'student_preference_rank' => '1',
				'match_status'            => '1',
				'available_hours'         => '20',
				'enrollment_status'       => '3',
			),
		);

		return ( new TAAS_REST_Matching_Controller() )->run(
			new WP_REST_Request(
				array(
					'term' => 'Spring',
					'year' => 2027,
				)
			)
		);
	}

	public function test_run_saves_recommendations_and_reports_what_is_left(): void {
		// 25 students with a 2-hour lab need 10 TA hours, half the student's 20
		$data = $this->run_matching_with_enrollment( 25 )->get_data();

		$this->assertSame(
			array(
				'term'                     => 'Spring',
				'year'                     => 2027,
				'recommendations_made'     => 1,
				'unfilled_hours'           => 0,
				'students_with_hours_left' => array(
					array(
						'student_ufid' => '12345678',
						'hours'        => 10,
					),
				),
			),
			$data
		);
		$this->assertSame( array( 'START TRANSACTION', 'DELETE FROM `wp_taas_core_match_recommendations`', 'COMMIT' ), $this->db->queries );
		$this->assertSame( 'wp_taas_core_match_recommendations', $this->db->write['table'] );
		$this->assertSame( 10, $this->db->write['data']['recommended_hours'] );
	}

	public function test_run_with_a_course_under_10_students_is_409_and_saves_nothing(): void {
		$result = $this->run_matching_with_enrollment( 5 );

		$this->assertSame( 'taas_matching_failed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->db->queries );
	}

	public function test_recommendations_are_empty_for_a_term_with_no_courses(): void {
		$result = ( new TAAS_REST_Matching_Controller() )->get_recommendations(
			new WP_REST_Request(
				array(
					'term'     => 'Spring',
					'year'     => 2027,
					'page'     => 1,
					'per_page' => 50,
				)
			)
		);

		$this->assertSame( array(), $result->get_data() );
		$this->assertSame( '0', $result->get_headers()['X-WP-Total'] );
	}

	public function test_me_reports_the_callers_role(): void {
		$_GET['taas_role'] = 'hr';

		$this->assertSame(
			array(
				'name'         => 'Test HR',
				'is_admin'     => false,
				'is_hr'        => true,
				'is_professor' => false,
				'is_student'   => false,
			),
			( new TAAS_REST_Me_Controller() )->get_item( new WP_REST_Request() )->get_data()
		);
	}
}
