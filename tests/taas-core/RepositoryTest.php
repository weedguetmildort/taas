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
	 * A professor's term lookup matches professor, semester and year together
	 */
	public function test_find_by_professor_and_term_filters_on_all_three(): void {
		( new TAAS_Course_Repository() )->find_by_professor_and_term( '87654321', TAAS_Semester::Summer_B, 2026 );

		$this->assertSame(
			"SELECT * FROM `wp_taas_core_courses` WHERE `professor_ufid` = '87654321' AND `course_semester` = '4' AND `course_year` = '2026' ORDER BY `course_id`",
			$this->db->sql
		);
	}

	/**
	 * Updating notes writes only course_notes, keyed by course_id
	 */
	public function test_update_notes_writes_only_course_notes(): void {
		$changed = ( new TAAS_Course_Repository() )->update_notes( '12345', 'Needs React.' );

		$this->assertTrue( $changed );
		$this->assertSame(
			array(
				'table' => 'wp_taas_core_courses',
				'data'  => array( 'course_notes' => 'Needs React.' ),
				'where' => array( 'course_id' => '12345' ),
			),
			$this->db->write
		);
	}

	/**
	 * Null notes are written as NULL, and an unchanged row reports false
	 */
	public function test_update_notes_clears_with_null(): void {
		$this->db->update_result = 0;

		$changed = ( new TAAS_Course_Repository() )->update_notes( '12345', null );

		$this->assertFalse( $changed );
		$this->assertSame( array( 'course_notes' => null ), $this->db->write['data'] );
	}

	/**
	 * A failed targeted update surfaces as an exception
	 */
	public function test_update_notes_throws_on_failure(): void {
		$this->db->update_result = false;

		$this->expectException( RuntimeException::class );
		( new TAAS_Course_Repository() )->update_notes( '12345', 'x' );
	}

	/**
	 * Withdrawn applications are dropped from the active list
	 */
	public function test_find_active_by_course_skips_withdrawn(): void {
		$this->db->rows = array(
			array(
				'application_id'    => '1',
				'student_ufid'      => '20000001',
				'applied_course_id' => '12345',
				'match_status'      => '1',
			),
			array(
				'application_id'    => '2',
				'student_ufid'      => '20000002',
				'applied_course_id' => '12345',
				'match_status'      => '4',
			),
			array(
				'application_id'    => '3',
				'student_ufid'      => '20000003',
				'applied_course_id' => '12345',
				'match_status'      => '2',
			),
		);

		$active = ( new TAAS_Application_Repository() )->find_active_by_course( '12345' );

		$this->assertSame( array( 1, 3 ), array_map( static fn( $a ) => $a->application_id, $active ) );
		$this->assertStringContainsString( "`applied_course_id` = '12345'", $this->db->sql );
	}

	/**
	 * The applicant list joins students and profiles, skips withdrawn and sorts by name
	 */
	public function test_applicants_for_course_builds_join(): void {
		( new TAAS_Application_Repository() )->applicants_for_course( '12345' );

		$sql = $this->db->sql;

		$this->assertStringContainsString( 'FROM `wp_taas_core_applications` a', $sql );
		$this->assertStringContainsString( 'INNER JOIN `wp_taas_core_students` s ON s.student_ufid = a.student_ufid', $sql );
		$this->assertStringContainsString( 'LEFT JOIN `wp_taas_core_student_profiles` p ON p.student_ufid = a.student_ufid', $sql );
		$this->assertStringContainsString( "WHERE a.applied_course_id = '12345' AND a.match_status <> 4", $sql );
		$this->assertStringEndsWith( 'ORDER BY s.student_name, a.application_id', $sql );
	}

	/**
	 * Joined rows come back with native types, and a missing profile reads as nulls
	 */
	public function test_applicants_for_course_casts_values(): void {
		$this->db->rows = array(
			array(
				'application_id'    => '7',
				'student_ufid'      => '20000002',
				'student_name'      => 'Priya Raman',
				'major'             => 'Computer Science',
				'gpa'               => '3.85',
				'enrollment_status' => '4',
				'has_taken_course'  => '0',
				'has_ta_for_course' => '1',
			),
			array(
				'application_id'    => '8',
				'student_ufid'      => '20000009',
				'student_name'      => 'No Profile',
				'major'             => null,
				'gpa'               => null,
				'enrollment_status' => null,
				'has_taken_course'  => '1',
				'has_ta_for_course' => '0',
			),
		);

		$rows = ( new TAAS_Application_Repository() )->applicants_for_course( '12345' );

		$this->assertSame(
			array(
				'application_id'    => 7,
				'student_ufid'      => '20000002',
				'student_name'      => 'Priya Raman',
				'major'             => 'Computer Science',
				'gpa'               => 3.85,
				'enrollment_status' => TAAS_Enrollment_Status::Phd,
				'has_taken_course'  => false,
				'has_ta_for_course' => true,
			),
			$rows[0]
		);
		$this->assertNull( $rows[1]['major'] );
		$this->assertNull( $rows[1]['gpa'] );
		$this->assertNull( $rows[1]['enrollment_status'] );
		$this->assertTrue( $rows[1]['has_taken_course'] );
	}

	/**
	 * Saving a ranking clears the course's ranks first, then sets 1..n in order
	 */
	public function test_set_professor_ranks_resets_then_sets_in_order(): void {
		( new TAAS_Application_Repository() )->set_professor_ranks( '12345', array( '20000002', '20000001' ) );

		$table = 'wp_taas_core_applications';

		$this->assertSame(
			array(
				array(
					'table' => $table,
					'data'  => array( 'professor_preference_rank' => null ),
					'where' => array( 'applied_course_id' => '12345' ),
				),
				array(
					'table' => $table,
					'data'  => array( 'professor_preference_rank' => 1 ),
					'where' => array(
						'student_ufid'      => '20000002',
						'applied_course_id' => '12345',
					),
				),
				array(
					'table' => $table,
					'data'  => array( 'professor_preference_rank' => 2 ),
					'where' => array(
						'student_ufid'      => '20000001',
						'applied_course_id' => '12345',
					),
				),
			),
			$this->db->writes
		);
	}

	/**
	 * An empty ranking only clears
	 */
	public function test_set_professor_ranks_empty_only_clears(): void {
		( new TAAS_Application_Repository() )->set_professor_ranks( '12345', array() );

		$this->assertCount( 1, $this->db->writes );
		$this->assertSame( array( 'professor_preference_rank' => null ), $this->db->writes[0]['data'] );
	}

	/**
	 * A failed rank write surfaces, so the transaction around it rolls back
	 */
	public function test_set_professor_ranks_rolls_back_on_failure(): void {
		$this->db->update_result = false;

		try {
			TAAS_Transaction::run(
				static fn() => ( new TAAS_Application_Repository() )->set_professor_ranks( '12345', array( '20000001' ) )
			);
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Update of applications failed', $e->getMessage() );
		}

		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->db->queries );
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
	 * Both per-course experience flags reach the applications insert as 0/1
	 */
	public function test_application_insert_writes_has_ta_for_course(): void {
		( new TAAS_Application_Repository() )->insert(
			new TAAS_Application( null, '12345678', '1234', has_taken_course: true, has_ta_for_course: true )
		);

		$this->assertSame( 'wp_taas_core_applications', $this->db->write['table'] );
		$this->assertSame( 1, $this->db->write['data']['has_taken_course'] );
		$this->assertSame( 1, $this->db->write['data']['has_ta_for_course'] );
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
