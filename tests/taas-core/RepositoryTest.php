<?php
/**
 * Tests for the repository layer
 *
 * @package TAAS_Core
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/support/class-taas-join-test-repository.php';
require_once dirname( __DIR__ ) . '/support/class-taas-fake-wpdb.php';

/**
 * Check repositories build the right queries and hydrate the right types
 */
class RepositoryTest extends TestCase {

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

	/**
	 * Swap in the fake $wpdb
	 */
	protected function setUp(): void {
		global $wpdb;

		$this->original_wpdb = $wpdb ?? null;
		$this->db            = new TAAS_Fake_Wpdb();
		$wpdb                = $this->db;
	}

	/**
	 * Put the real global back
	 */
	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;
	}

	/**
	 * A found row comes back as the typed row class
	 */
	public function test_find_returns_hydrated_entity(): void {
		$this->db->row = array(
			'course_id'       => '12345',
			'course_code'     => 'COP3502',
			'course_name'     => 'Programming 1',
			'course_semester' => '1',
			'course_year'     => '2026',
			'is_online'       => '1',
			'professor_ufid'  => '87654321',
			'enrollment'      => '30',
		);

		$course = ( new TAAS_Course_Repository() )->find( '12345' );

		$this->assertInstanceOf( TAAS_Course::class, $course );
		$this->assertSame( 30, $course->enrollment );
		$this->assertSame( TAAS_Semester::Fall, $course->course_semester );
		$this->assertSame( 2026, $course->course_year );
		$this->assertTrue( $course->is_online );
		$this->assertSame(
			"SELECT * FROM `wp_taas_core_courses` WHERE `course_id` = '12345'",
			$this->db->sql
		);
	}

	/**
	 * No row means null, not an error
	 */
	public function test_find_returns_null_when_missing(): void {
		$this->assertNull( ( new TAAS_Course_Repository() )->find( 'nope' ) );
	}

	/**
	 * A database error surfaces as an exception
	 */
	public function test_find_throws_on_database_error(): void {
		$this->db->last_error = 'connection lost';

		$this->expectException( RuntimeException::class );
		( new TAAS_Course_Repository() )->find( '12345' );
	}

	/**
	 * Page size is clamped to the maximum and offset to zero
	 */
	public function test_all_clamps_limit_and_offset(): void {
		( new TAAS_Student_Repository() )->all( 5000, -10 );

		$this->assertStringContainsString( 'LIMIT 100 OFFSET 0', $this->db->sql );
		$this->assertStringContainsString( 'ORDER BY `student_ufid`', $this->db->sql );
	}

	/**
	 * Lookups by foreign key filter on the right column
	 */
	public function test_find_by_student_filters_on_student_ufid(): void {
		$this->db->rows = array(
			array(
				'application_id'    => '7',
				'student_ufid'      => '12345678',
				'applied_course_id' => '12345',
				'match_status'      => '1',
			),
		);

		$applications = ( new TAAS_Application_Repository() )->find_by_student( '12345678' );

		$this->assertCount( 1, $applications );
		$this->assertSame( 7, $applications[0]->application_id );
		$this->assertStringContainsString( "`student_ufid` = '12345678'", $this->db->sql );
	}

	/**
	 * A term lookup matches both semester and year, joined with AND
	 */
	public function test_find_by_term_filters_on_semester_and_year(): void {
		$this->db->rows = array(
			array(
				'course_id'       => '12345',
				'course_code'     => 'COP3502',
				'course_name'     => 'Programming 1',
				'course_semester' => '3',
				'course_year'     => '2026',
				'professor_ufid'  => '87654321',
			),
		);

		$courses = ( new TAAS_Course_Repository() )->find_by_term( TAAS_Semester::Summer_A, 2026 );

		$this->assertCount( 1, $courses );
		$this->assertSame( 'Summer A 2026', $courses[0]->term_label() );
		$this->assertSame(
			"SELECT * FROM `wp_taas_core_courses` WHERE `course_semester` = '3' AND `course_year` = '2026' ORDER BY `course_id`",
			$this->db->sql
		);
	}

	/**
	 * Insert on a natural-key table returns the key
	 */
	public function test_insert_returns_primary_key_for_natural_key_table(): void {
		$key = ( new TAAS_Student_Repository() )->insert( new TAAS_Student( '12345678', 'Ada', 'ada@ufl.edu' ) );

		$this->assertSame( '12345678', $key );
		$this->assertSame( 'wp_taas_core_students', $this->db->write['table'] );
	}

	/**
	 * Insert on an auto-increment table returns the new id
	 */
	public function test_insert_returns_insert_id_for_auto_increment_table(): void {
		$this->db->insert_id = 42;

		$id = ( new TAAS_Application_Repository() )->insert( new TAAS_Application( null, '12345678', '12345' ) );

		$this->assertSame( 42, $id );
	}

	/**
	 * A failed insert surfaces as an exception
	 */
	public function test_insert_throws_on_failure(): void {
		$this->db->insert_result = false;

		$this->expectException( RuntimeException::class );
		( new TAAS_Student_Repository() )->insert( new TAAS_Student( '12345678', 'Ada', 'ada@ufl.edu' ) );
	}

	/**
	 * Update never rewrites the primary key
	 */
	public function test_update_omits_primary_key_from_data(): void {
		( new TAAS_Student_Repository() )->update( '12345678', new TAAS_Student( '12345678', 'Ada L.', 'ada@ufl.edu' ) );

		$this->assertArrayNotHasKey( 'student_ufid', $this->db->write['data'] );
		$this->assertSame( array( 'student_ufid' => '12345678' ), $this->db->write['where'] );
	}

	/**
	 * A join comes back as plain arrays, not row objects
	 */
	public function test_select_rows_returns_raw_arrays(): void {
		$expected = array(
			array(
				'course_id'    => '12345',
				'student_name' => 'Ada',
			),
		);

		$this->db->rows = $expected;

		$rows = ( new TAAS_Join_Test_Repository() )->joined( 'SELECT 1' );

		$this->assertSame( $expected, $rows );
		$this->assertSame( 'SELECT 1', $this->db->sql );
	}

	/**
	 * A failed join surfaces as an exception
	 */
	public function test_select_rows_throws_on_database_error(): void {
		$this->db->last_error = 'syntax error';

		$this->expectException( RuntimeException::class );
		( new TAAS_Join_Test_Repository() )->joined( 'SELECT 1' );
	}

	/**
	 * A transaction that finishes cleanly commits and returns the callback's value
	 */
	public function test_transaction_commits_on_success(): void {
		$result = TAAS_Transaction::run( static fn() => 'done' );

		$this->assertSame( 'done', $result );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->db->queries );
	}

	/**
	 * A throwing callback rolls back and the exception still reaches the caller
	 */
	public function test_transaction_rolls_back_and_rethrows(): void {
		try {
			TAAS_Transaction::run(
				static function () {
					throw new RuntimeException( 'second insert failed' );
				}
			);
			$this->fail( 'Expected the exception to propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'second insert failed', $e->getMessage() );
		}

		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->db->queries );
	}

	/**
	 * A nested transaction joins the outer one and only the outer commits
	 */
	public function test_transaction_nested_commits_once(): void {
		TAAS_Transaction::run( static fn() => TAAS_Transaction::run( static fn() => null ) );

		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->db->queries );
	}

	/**
	 * After a rollback the next transaction starts fresh
	 */
	public function test_transaction_is_reusable_after_failure(): void {
		try {
			TAAS_Transaction::run(
				static function () {
					throw new RuntimeException( 'boom' );
				}
			);
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->db->queries = array();
		TAAS_Transaction::run( static fn() => null );

		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->db->queries );
	}

	/**
	 * If the transaction cannot start, the callback never runs
	 */
	public function test_transaction_start_failure_skips_callback(): void {
		$this->db->fail_query = 'START TRANSACTION';
		$ran                  = false;

		try {
			TAAS_Transaction::run(
				static function () use ( &$ran ) {
					$ran = true;
				}
			);
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( RuntimeException $e ) {
			$this->assertFalse( $ran );
		}
	}

	/**
	 * A row from another table is refused before touching the database
	 */
	public function test_insert_rejects_wrong_row_class(): void {
		$this->expectException( InvalidArgumentException::class );
		( new TAAS_Student_Repository() )->insert( new TAAS_Application( null, '12345678', '12345' ) );
	}
}
