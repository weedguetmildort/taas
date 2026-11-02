<?php
/**
 * Tests for the application cycle: its type, repository and routes
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

/**
 * Check the rules for setting a cycle and working out its active stage
 */
class ApplicationCycleTest extends TestCase {

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

	/**
	 * A stored row with the given date columns
	 */
	private static function row( array $dates = array() ): array {
		return $dates + array(
			'cycle_id'       => '1',
			'cycle_semester' => '2',
			'cycle_year'     => '2027',
		);
	}

	/**
	 * A date entry for a request, far enough ahead not to have come
	 */
	private static function future_dates( string $start_at = '2126-01-01T00:00:00Z', string $end_at = '2126-02-01T00:00:00Z' ): array {
		return array(
			'start_at' => $start_at,
			'end_at'   => $end_at,
		);
	}

	/**
	 * The stage active at a time, for a cycle with the given date columns
	 */
	private static function stage_at( string $now, array $dates ): ?TAAS_Cycle_Stage {
		return TAAS_Application_Cycle::from_row( self::row( $dates ) )->active_stage( new DateTimeImmutable( $now, new DateTimeZone( 'UTC' ) ) );
	}

	private function put( array $params = array() ) {
		return ( new TAAS_REST_Application_Cycle_Controller() )->replace_item(
			new WP_REST_Request(
				$params + array(
					'term' => 'Spring',
					'year' => 2027,
				)
			)
		);
	}

	private function patch( array $params ) {
		return ( new TAAS_REST_Application_Cycle_Controller() )->update_item( new WP_REST_Request( $params ) );
	}

	public function test_stored_values_reach_the_api_as_logical_values(): void {
		$row = array(
			'cycle_semester'           => '3',
			'cycle_year'               => '2027',
			'hr_review_start_at'       => '2026-11-16 05:00:00',
			'hr_review_end_at'         => '2026-11-23 04:59:59',
			'admin_matching_end_at'    => '2026-12-21 04:59:59',
			'professor_ranking_end_at' => null,
		);

		$this->assertSame(
			array(
				'term'        => 'Summer A',
				'year'        => 2027,
				'stage'       => 'hr_review',
				'stage_dates' => array(
					'student_application' => null,
					'hr_review'           => array(
						'start_at' => '2026-11-16T05:00:00Z',
						'end_at'   => '2026-11-23T04:59:59Z',
					),
					'professor_ranking'   => null,
					'admin_matching'      => array(
						'start_at' => null,
						'end_at'   => '2026-12-21T04:59:59Z',
					),
				),
			),
			TAAS_Application_Cycle::from_row( $row )->to_response( new DateTimeImmutable( '2026-11-20 00:00:00', new DateTimeZone( 'UTC' ) ) )
		);
	}

	public function test_to_row_stores_utc_datetimes_and_nulls(): void {
		$start = TAAS_Application_Cycle::parse_api_date( '2026-10-15T04:00:00Z' );
		$row   = ( new TAAS_Application_Cycle(
			TAAS_Semester::Spring,
			2027,
			array(
				'student_application' => array(
					'start_at' => $start,
					'end_at'   => null,
				),
			)
		) )->to_row();

		$this->assertSame( 1, $row['cycle_id'] );
		$this->assertSame( 2, $row['cycle_semester'] );
		$this->assertArrayNotHasKey( 'stage', $row );
		$this->assertSame( '2026-10-15 04:00:00', $row['student_application_start_at'] );
		$this->assertNull( $row['student_application_end_at'] );
		$this->assertNull( $row['hr_review_start_at'] );
	}

	public function test_parse_api_date_accepts_only_real_utc_timestamps(): void {
		$this->assertNotNull( TAAS_Application_Cycle::parse_api_date( '2026-11-16T04:59:59Z' ) );
		$this->assertNull( TAAS_Application_Cycle::parse_api_date( '2026-02-31T00:00:00Z' ) );
		$this->assertNull( TAAS_Application_Cycle::parse_api_date( '2026-11-16T04:59:59-05:00' ) );
		$this->assertNull( TAAS_Application_Cycle::parse_api_date( '2026-11-16' ) );
	}

	public function test_a_stage_is_active_from_its_start_at_until_its_end_at(): void {
		$dates = array(
			'hr_review_start_at' => '2026-11-16 05:00:00',
			'hr_review_end_at'   => '2026-11-23 04:59:59',
		);

		$this->assertNull( self::stage_at( '2026-11-16 04:59:59', $dates ) );
		$this->assertSame( TAAS_Cycle_Stage::Hr_Review, self::stage_at( '2026-11-16 05:00:00', $dates ) );
		$this->assertSame( TAAS_Cycle_Stage::Hr_Review, self::stage_at( '2026-11-23 04:59:58', $dates ) );
		$this->assertNull( self::stage_at( '2026-11-23 04:59:59', $dates ) );
	}

	public function test_one_stage_ending_does_not_start_the_next(): void {
		$dates = array(
			'student_application_start_at' => '2026-10-15 00:00:00',
			'student_application_end_at'   => '2026-11-16 04:59:59',
			'hr_review_start_at'           => '2026-11-20 00:00:00',
		);

		$this->assertNull( self::stage_at( '2026-11-18 00:00:00', $dates ) );
		$this->assertSame( TAAS_Cycle_Stage::Hr_Review, self::stage_at( '2026-11-20 00:00:00', $dates ) );
	}

	public function test_a_stage_with_no_end_at_runs_until_a_later_stage_starts(): void {
		$dates = array(
			'hr_review_start_at'         => '2026-11-16 05:00:00',
			'professor_ranking_start_at' => '2026-11-23 05:00:00',
			'professor_ranking_end_at'   => '2026-12-07 04:59:59',
		);

		$this->assertSame( TAAS_Cycle_Stage::Hr_Review, self::stage_at( '2026-11-20 00:00:00', $dates ) );
		$this->assertSame( TAAS_Cycle_Stage::Professor_Ranking, self::stage_at( '2026-11-24 00:00:00', $dates ) );
	}

	public function test_an_earlier_stage_with_no_end_at_does_not_reopen_when_a_later_one_ends(): void {
		$dates = array(
			'hr_review_start_at'         => '2026-11-16 05:00:00',
			'professor_ranking_start_at' => '2026-11-23 05:00:00',
			'professor_ranking_end_at'   => '2026-12-07 04:59:59',
		);

		$this->assertNull( self::stage_at( '2026-12-08 00:00:00', $dates ) );
	}

	public function test_a_stage_with_no_start_at_never_starts(): void {
		$this->assertNull( self::stage_at( '2026-11-20 00:00:00', array( 'hr_review_end_at' => '2026-12-01 00:00:00' ) ) );
	}

	public function test_no_dates_means_no_active_stage(): void {
		$this->assertNull( self::stage_at( '2026-11-20 00:00:00', array() ) );
	}

	public function test_reading_the_cycle_writes_nothing(): void {
		$this->db->row = self::row(
			array(
				'hr_review_start_at' => '2020-01-01 00:00:00',
				'hr_review_end_at'   => '2020-02-01 00:00:00',
			)
		);

		$result = ( new TAAS_REST_Application_Cycle_Controller() )->get_item( new WP_REST_Request() );

		$this->assertNull( $result->get_data()['stage'] );
		$this->assertSame( array(), $this->db->writes );
	}

	public function test_get_with_no_cycle_is_404(): void {
		$result = ( new TAAS_REST_Application_Cycle_Controller() )->get_item( new WP_REST_Request() );

		$this->assertSame( 'taas_no_current_cycle', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_put_with_no_dates_leaves_no_stage_active(): void {
		$result = $this->put();

		$this->assertNull( $result->get_data()['stage'] );
		$this->assertNull( $result->get_data()['stage_dates']['hr_review'] );
		$this->assertSame( 1, $this->db->write['data']['cycle_id'] );
	}

	public function test_put_with_dates_already_open_starts_the_stage(): void {
		$result = $this->put(
			array(
				'stage_dates' => array( 'hr_review' => self::future_dates( '2020-01-01T00:00:00Z', '2126-02-01T00:00:00Z' ) ),
			)
		);

		$this->assertSame( 'hr_review', $result->get_data()['stage'] );
	}

	/**
	 * Nothing is saved when stage_dates is not valid
	 *
	 * @dataProvider invalid_dates
	 */
	public function test_put_rejects_invalid_dates( mixed $stage_dates ): void {
		$result = $this->put( array( 'stage_dates' => $stage_dates ) );

		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertArrayHasKey( 'stage_dates', $result->get_error_data()['params'] );
		$this->assertSame( array(), $this->db->writes );
	}

	public static function invalid_dates(): array {
		return array(
			'not an object' => array( 'soon' ),
			'unknown stage' => array( array( 'grading' => null ) ),
			'one date only' => array( array( 'hr_review' => array( 'start_at' => '2126-01-01T00:00:00Z' ) ) ),
			'not a date'    => array( array( 'hr_review' => self::future_dates( 'tomorrow' ) ) ),
			'end before'    => array( array( 'hr_review' => self::future_dates( '2126-02-01T00:00:00Z', '2126-01-01T00:00:00Z' ) ) ),
			'overlap'       => array(
				array(
					'hr_review'         => self::future_dates( '2126-01-01T00:00:00Z', '2126-02-01T00:00:00Z' ),
					'professor_ranking' => self::future_dates( '2126-01-15T00:00:00Z', '2126-03-01T00:00:00Z' ),
				),
			),
			'shared moment' => array(
				array(
					'hr_review'         => self::future_dates( '2126-01-01T00:00:00Z', '2126-02-01T00:00:00Z' ),
					'professor_ranking' => self::future_dates( '2126-02-01T00:00:00Z', '2126-03-01T00:00:00Z' ),
				),
			),
		);
	}

	public function test_open_ended_stages_do_not_overlap(): void {
		$result = $this->put(
			array(
				'stage_dates' => array(
					'hr_review'         => array(
						'start_at' => '2126-01-01T00:00:00Z',
						'end_at'   => null,
					),
					'professor_ranking' => self::future_dates( '2126-01-15T00:00:00Z', '2126-03-01T00:00:00Z' ),
				),
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
	}

	public function test_patch_needs_dates(): void {
		$this->db->row = self::row();

		$this->assertSame( 'rest_missing_callback_param', $this->patch( array() )->get_error_code() );
	}

	public function test_patch_with_no_cycle_is_404(): void {
		$this->assertSame( 'taas_no_current_cycle', $this->patch( array( 'stage_dates' => array() ) )->get_error_code() );
	}

	public function test_patch_changes_only_the_stages_named_and_null_clears(): void {
		$this->db->row = self::row(
			array(
				'student_application_end_at' => '2126-11-16 04:59:59',
				'hr_review_end_at'           => '2126-12-01 00:00:00',
			)
		);

		$data = $this->patch(
			array(
				'stage_dates' => array(
					'student_application' => null,
					'admin_matching'      => self::future_dates( '2127-01-01T00:00:00Z', '2127-02-01T00:00:00Z' ),
				),
			)
		)->get_data();

		$this->assertNull( $data['stage_dates']['student_application'] );
		$this->assertSame( '2126-12-01T00:00:00Z', $data['stage_dates']['hr_review']['end_at'] );
		$this->assertSame( '2127-02-01T00:00:00Z', $data['stage_dates']['admin_matching']['end_at'] );
	}

	public function test_patch_future_dates_do_not_start_a_stage_yet(): void {
		$this->db->row = self::row();

		$data = $this->patch( array( 'stage_dates' => array( 'hr_review' => self::future_dates() ) ) )->get_data();

		$this->assertNull( $data['stage'] );
	}

	public function test_patch_a_start_at_that_has_come_starts_the_stage(): void {
		$this->db->row = self::row();

		$data = $this->patch( array( 'stage_dates' => array( 'hr_review' => self::future_dates( '2020-01-01T00:00:00Z', '2126-02-01T00:00:00Z' ) ) ) )->get_data();

		$this->assertSame( 'hr_review', $data['stage'] );
	}

	public function test_patch_an_end_at_that_has_come_ends_the_stage(): void {
		$this->db->row = self::row(
			array(
				'student_application_start_at' => '2020-01-01 00:00:00',
				'student_application_end_at'   => '2126-11-16 04:59:59',
			)
		);

		$data = $this->patch( array( 'stage_dates' => array( 'student_application' => self::future_dates( '2020-01-01T00:00:00Z', '2020-02-01T00:00:00Z' ) ) ) )->get_data();

		$this->assertNull( $data['stage'] );
	}

	public function test_patch_a_later_end_at_reopens_a_stage(): void {
		$this->db->row = self::row(
			array(
				'student_application_start_at' => '2020-01-01 00:00:00',
				'student_application_end_at'   => '2020-02-01 00:00:00',
			)
		);

		$data = $this->patch( array( 'stage_dates' => array( 'student_application' => self::future_dates( '2020-01-01T00:00:00Z', '2126-02-01T00:00:00Z' ) ) ) )->get_data();

		$this->assertSame( 'student_application', $data['stage'] );
	}

	public function test_patch_ignores_a_stage_sent_in_the_request(): void {
		$this->db->row = self::row();

		$data = $this->patch(
			array(
				'stage'       => 'hr_review',
				'stage_dates' => array(),
			)
		)->get_data();

		$this->assertNull( $data['stage'] );
	}
}
