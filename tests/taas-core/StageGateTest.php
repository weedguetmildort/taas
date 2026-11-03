<?php
/**
 * Tests for which callers each stage lets through
 *
 * @package TAAS_Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment, WordPress.Security.NonceVerification

use PHPUnit\Framework\TestCase;

if ( ! defined( 'TAAS_LOCAL_DEV' ) ) {
	define( 'TAAS_LOCAL_DEV', true );
}

require_once dirname( __DIR__ ) . '/support/class-wp-error.php';
require_once dirname( __DIR__ ) . '/support/class-wp-rest-request.php';
require_once dirname( __DIR__ ) . '/support/class-taas-fake-wpdb.php';

/**
 * Check non-admins reach the API only during their stage
 */
class StageGateTest extends TestCase {

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
	}

	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;
		unset( $_GET['taas_role'] );
	}

	private function set_stage( ?TAAS_Cycle_Stage $stage ): void {
		$this->db->row = array(
			'cycle_semester' => '2',
			'cycle_year'     => '2027',
		);

		if ( null !== $stage ) {
			$this->db->row[ $stage->label() . '_start_at' ] = '2020-01-01 00:00:00';
		}
	}

	private static function enforce( string $route = '/taas/v1/courses', string $method = 'GET' ) {
		return TAAS_Stage_Gate::enforce( 'untouched', array(), new WP_REST_Request( array(), array(), $method, $route ) );
	}

	/**
	 * Students, HR and professors each get exactly one stage
	 *
	 * @dataProvider role_stages
	 */
	public function test_each_role_is_let_through_only_in_its_stage( string $role, TAAS_Cycle_Stage $open_stage ): void {
		$_GET['taas_role'] = $role;

		foreach ( TAAS_Cycle_Stage::cases() as $stage ) {
			$this->set_stage( $stage );

			$result = self::enforce();

			if ( $stage === $open_stage ) {
				$this->assertSame( 'untouched', $result, $stage->label() );
			} else {
				$this->assertSame( 'taas_stage_closed', $result->get_error_code(), $stage->label() );
				$this->assertSame(
					array(
						'status' => 403,
						'stage'  => $stage->label(),
					),
					$result->get_error_data()
				);
			}
		}
	}

	public static function role_stages(): array {
		return array(
			'student'   => array( 'student', TAAS_Cycle_Stage::Student_Application ),
			'hr'        => array( 'hr', TAAS_Cycle_Stage::Hr_Review ),
			'professor' => array( 'instructor', TAAS_Cycle_Stage::Professor_Ranking ),
		);
	}

	public function test_non_admins_are_closed_out_when_no_stage_is_active(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( null );

		$result = self::enforce();

		$this->assertSame( 'taas_stage_closed', $result->get_error_code() );
		$this->assertNull( $result->get_error_data()['stage'] );
	}

	public function test_non_admins_are_closed_out_when_no_cycle_exists(): void {
		$_GET['taas_role'] = 'instructor';

		$this->assertSame( 'taas_stage_closed', self::enforce()->get_error_code() );
	}

	public function test_a_passed_end_at_closes_the_stage(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( TAAS_Cycle_Stage::Student_Application );
		$this->db->row['student_application_end_at'] = '2020-01-01 00:00:00';

		$this->assertSame( 'taas_stage_closed', self::enforce()->get_error_code() );
	}

	public function test_admins_are_never_closed_out(): void {
		$_GET['taas_role'] = 'admin';
		$this->set_stage( null );

		$this->assertSame( 'untouched', self::enforce() );
	}

	public function test_me_and_reading_the_cycle_stay_open(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( null );

		$this->assertSame( 'untouched', self::enforce( '/taas/v1/me' ) );
		$this->assertSame( 'untouched', self::enforce( '/taas/v1/application-cycle' ) );
	}

	public function test_students_can_read_their_applications_in_any_stage(): void {
		$_GET['taas_role'] = 'student';

		foreach ( array_merge( TAAS_Cycle_Stage::cases(), array( null ) ) as $stage ) {
			$this->set_stage( $stage );

			foreach ( array( '/taas/v1/student/application', '/taas/v1/student/application/terms', '/taas/v1/student/application/2/2027/' ) as $route ) {
				$this->assertSame( 'untouched', self::enforce( $route ), $route . ' in ' . ( $stage?->label() ?? 'no stage' ) );
			}
		}
	}

	public function test_students_cannot_write_applications_outside_their_stage(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( TAAS_Cycle_Stage::Hr_Review );

		$this->assertSame( 'taas_stage_closed', self::enforce( '/taas/v1/student/application', 'POST' )->get_error_code() );
		$this->assertSame( 'taas_stage_closed', self::enforce( '/taas/v1/student/application/2/2027', 'POST' )->get_error_code() );
		$this->assertSame( 'taas_stage_closed', self::enforce( '/taas/v1/student/application/2/2027', 'PUT' )->get_error_code() );
	}

	public function test_only_the_student_application_routes_are_exempt(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( null );

		$this->assertSame( 'taas_stage_closed', self::enforce( '/taas/v1/student/applications' )->get_error_code() );
		$this->assertSame( 'taas_stage_closed', self::enforce( '/taas/v1/student/application/2/2027/extra' )->get_error_code() );
		$this->assertSame( 'taas_stage_closed', self::enforce( '/taas/v1/students' )->get_error_code() );
	}

	public function test_writing_the_cycle_is_not_exempt(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( null );

		$this->assertSame( 'taas_stage_closed', self::enforce( '/taas/v1/application-cycle', 'PATCH' )->get_error_code() );
	}

	public function test_routes_outside_the_namespace_are_left_alone(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( null );

		$this->assertSame( 'untouched', self::enforce( '/wp/v2/posts' ) );
	}

	public function test_route_case_cannot_slip_past_the_gate(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( null );

		$this->assertSame( 'taas_stage_closed', self::enforce( '/TAAS/V1/Courses' )->get_error_code() );
	}

	public function test_a_database_failure_does_not_open_the_gate(): void {
		$_GET['taas_role'] = 'student';
		$this->db->last_error = 'gone away';

		$this->assertSame( 'taas_server_error', self::enforce()->get_error_code() );
	}

	public function test_an_invalid_request_is_still_closed_outside_the_stage(): void {
		$_GET['taas_role'] = 'student';
		$this->set_stage( null );

		$result = TAAS_Stage_Gate::enforce( new WP_Error( 'rest_invalid_param' ), array(), new WP_REST_Request( array(), array(), 'GET', '/taas/v1/courses' ) );

		$this->assertSame( 'taas_stage_closed', $result->get_error_code() );
	}
}
