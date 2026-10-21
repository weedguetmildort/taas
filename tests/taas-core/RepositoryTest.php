<?php
/**
 * Tests for the repository layer
 *
 * @package TAAS_Core
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stand-in for the WordPress escaper, which tests run without.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text );
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	// WordPress defines this; repositories pass it to $wpdb reads.
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Repository with a public door onto select_rows(), as a join method would use it
 */
class TAAS_Join_Test_Repository extends TAAS_Repository {

	/**
	 * Bare table name
	 *
	 * @return string
	 */
	protected function bare_table(): string {
		return 'courses';
	}

	/**
	 * Primary key column
	 *
	 * @return string
	 */
	protected function primary_key(): string {
		return 'course_id';
	}

	/**
	 * Row class
	 *
	 * @return string
	 */
	protected function row_class(): string {
		return TAAS_Course::class;
	}

	/**
	 * Run a prepared query through select_rows()
	 *
	 * @param string $sql Prepared SELECT.
	 * @return array
	 */
	public function joined( string $sql ): array {
		return $this->select_rows( $sql );
	}
}

/**
 * Minimal $wpdb that records what repositories ask of it
 */
class TAAS_Fake_Wpdb {

	/**
	 * Table prefix
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Error message the next check will see
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Id of the last auto-increment insert
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Row get_row() returns
	 *
	 * @var array|null
	 */
	public $row = null;

	/**
	 * Rows get_results() returns
	 *
	 * @var array
	 */
	public $rows = array();

	/**
	 * Value insert() returns
	 *
	 * @var int|false
	 */
	public $insert_result = 1;

	/**
	 * SQL passed to the last read
	 *
	 * @var string
	 */
	public $sql = '';

	/**
	 * Arguments passed to the last write
	 *
	 * @var array
	 */
	public $write = array();

	/**
	 * Substitute %i, %s and %d the way $wpdb->prepare() does
	 *
	 * @param string $query SQL with placeholders.
	 * @param mixed  ...$args Values, in order.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return preg_replace_callback(
			'/%[isd]/',
			static function ( $m ) use ( &$args ) {
				$value = array_shift( $args );
				return match ( $m[0] ) {
					'%i' => '`' . $value . '`',
					'%s' => "'" . $value . "'",
					default => (string) (int) $value,
				};
			},
			$query
		);
	}

	/**
	 * Record the query and return the canned row
	 *
	 * @param string $query SQL.
	 * @return array|null
	 */
	public function get_row( $query ) {
		$this->sql = $query;
		return $this->row;
	}

	/**
	 * Record the query and return the canned rows
	 *
	 * @param string $query SQL.
	 * @return array
	 */
	public function get_results( $query ) {
		$this->sql = $query;
		return $this->rows;
	}

	/**
	 * Every statement passed to query(), in order
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Statement query() should fail on, or null for none
	 *
	 * @var string|null
	 */
	public $fail_query = null;

	/**
	 * Record a raw statement such as START TRANSACTION
	 *
	 * @param string $sql Statement.
	 * @return int|false
	 */
	public function query( $sql ) {
		$this->queries[] = $sql;
		return $sql === $this->fail_query ? false : 0;
	}

	/**
	 * Record the insert
	 *
	 * @param string $table Table name.
	 * @param array  $data Column => value.
	 * @return int|false
	 */
	public function insert( $table, $data ) {
		$this->write = compact( 'table', 'data' );
		return $this->insert_result;
	}

	/**
	 * Record the update
	 *
	 * @param string $table Table name.
	 * @param array  $data Column => value.
	 * @param array  $where Column => value.
	 * @return int
	 */
	public function update( $table, $data, $where ) {
		$this->write = compact( 'table', 'data', 'where' );
		return 1;
	}
}

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
			'course_id'      => '12345',
			'course_code'    => 'COP3502',
			'course_name'    => 'Programming 1',
			'professor_ufid' => '87654321',
			'enrollment'     => '30',
		);

		$course = ( new TAAS_Course_Repository() )->find( '12345' );

		$this->assertInstanceOf( TAAS_Course::class, $course );
		$this->assertSame( 30, $course->enrollment );
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
		$this->db->rows = array( array( 'course_id' => '12345', 'student_name' => 'Ada' ) );

		$rows = ( new TAAS_Join_Test_Repository() )->joined( 'SELECT 1' );

		$this->assertSame( array( array( 'course_id' => '12345', 'student_name' => 'Ada' ) ), $rows );
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
