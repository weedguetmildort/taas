<?php
/**
 * Tests for the student-facing view of the application cycle
 *
 * @package TAAS_Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/support/class-wp-error.php';
require_once dirname( __DIR__ ) . '/support/class-taas-fake-wpdb.php';

/**
 * Check the period follows the cycle's student_application stage
 */
class ApplicationPeriodTest extends TestCase {

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
	}

	private function set_cycle( array $dates ): void {
		$this->db->row = array(
			'cycle_semester' => '2',
			'cycle_year'     => '2027',
		) + $dates;
	}

	public function test_no_cycle_is_unconfigured(): void {
		$result = TAAS_Application_Period::current();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'taas_period_unconfigured', $result->get_error_code() );
		$this->assertSame( array( 'status' => 503 ), $result->get_error_data() );
	}

	public function test_an_active_student_stage_is_open_with_its_deadline(): void {
		$this->set_cycle(
			array(
				'student_application_start_at' => '2020-01-01 00:00:00',
				'student_application_end_at'   => '2154-01-01 00:00:00',
			)
		);

		$this->assertSame(
			array(
				'semester'  => 2,
				'year'      => 2027,
				'label'     => 'Spring 2027',
				'is_open'   => true,
				'closes_at' => gmmktime( 0, 0, 0, 1, 1, 2154 ),
			),
			TAAS_Application_Period::current()
		);
	}

	public function test_no_end_at_means_no_deadline(): void {
		$this->set_cycle( array( 'student_application_start_at' => '2020-01-01 00:00:00' ) );

		$result = TAAS_Application_Period::current();

		$this->assertTrue( $result['is_open'] );
		$this->assertNull( $result['closes_at'] );
	}

	public function test_a_passed_end_at_closes_applications(): void {
		$this->set_cycle(
			array(
				'student_application_start_at' => '2020-01-01 00:00:00',
				'student_application_end_at'   => '2020-02-01 00:00:00',
			)
		);

		$result = TAAS_Application_Period::current();

		$this->assertFalse( $result['is_open'] );
		$this->assertSame( gmmktime( 0, 0, 0, 2, 1, 2020 ), $result['closes_at'] );
	}

	public function test_a_later_stage_closes_applications(): void {
		$this->set_cycle(
			array(
				'student_application_start_at' => '2020-01-01 00:00:00',
				'hr_review_start_at'           => '2020-02-01 00:00:00',
			)
		);

		$this->assertFalse( TAAS_Application_Period::current()['is_open'] );
	}

	public function test_a_future_start_is_not_yet_open(): void {
		$this->set_cycle( array( 'student_application_start_at' => '2154-01-01 00:00:00' ) );

		$this->assertFalse( TAAS_Application_Period::current()['is_open'] );
	}

	public function test_a_database_failure_is_thrown(): void {
		$this->db->last_error = 'gone away';

		$this->expectException( RuntimeException::class );
		TAAS_Application_Period::current();
	}
}
