<?php
/**
 * Tests for the XLSX course import
 *
 * @package TAAS_Core
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/support/class-taas-fake-wpdb.php';
require_once dirname( __DIR__ ) . '/support/class-taas-course-import-fake-wpdb.php';

// Plain functions are not covered by the Composer classmap
require_once dirname( __DIR__, 2 ) . '/htdocs/wp-content/plugins/taas-core/includes/func-taas-xlsx-ingest.php';

/**
 * Check headers are matched, cells are validated against the schema, and rows are saved or skipped
 */
class CourseImportTest extends TestCase {

	/**
	 * Every header the importer knows, in the order the real spreadsheet uses
	 */
	private const HEADERS = array(
		'Section',
		'Prefix',
		'Title',
		'Semester',
		'Year',
		'UFO',
		'Description',
		'Notes',
		'Lab Hour',
		'Current Enrollment',
		'Maximum Enrollment',
		'Professor Teaching',
		'GLID',
	);

	/**
	 * Original global, restored after each test
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Fake database for the test
	 *
	 * @var TAAS_Course_Import_Fake_Wpdb
	 */
	private TAAS_Course_Import_Fake_Wpdb $db;

	/**
	 * Spreadsheets written by the test, deleted afterwards
	 *
	 * @var string[]
	 */
	private array $files = array();

	/**
	 * Swap in the fake $wpdb, describing the tables as sql/schema.sql does
	 */
	protected function setUp(): void {
		global $wpdb;

		$this->original_wpdb = $wpdb ?? null;
		$this->db            = new TAAS_Course_Import_Fake_Wpdb();
		$wpdb                = $this->db;

		$this->db->columns = array(
			'wp_taas_core_courses'    => array_values( self::course_fields() ),
			'wp_taas_core_professors' => array_values( self::professor_fields() ),
		);

		$this->db->professors = array(
			array( '11111111', 'Jane Doe', 'jdoe@ufl.edu' ),
			array( '22222222', 'Alan Turing', 'aturing@ufl.edu' ),
		);
	}

	/**
	 * Put the real global back and delete any spreadsheets
	 */
	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;

		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * Build a SHOW COLUMNS row
	 *
	 * @param string      $name          Field name.
	 * @param string      $type          MySQL type, as SHOW COLUMNS prints it.
	 * @param bool        $nullable      Whether the field allows NULL.
	 * @param string|null $default_value Default value, or null for none.
	 * @return array
	 */
	private static function field( string $name, string $type, bool $nullable = false, ?string $default_value = null ): array {
		return array(
			'Field'   => $name,
			'Type'    => $type,
			'Null'    => $nullable ? 'YES' : 'NO',
			'Key'     => '',
			'Default' => $default_value,
			'Extra'   => '',
		);
	}

	/**
	 * The courses table, as SHOW COLUMNS describes it
	 *
	 * @return array<string, array>
	 */
	private static function course_fields(): array {
		$fields = array(
			self::field( 'course_id', 'varchar(20)' ),
			self::field( 'course_code', 'varchar(20)' ),
			self::field( 'course_name', 'varchar(255)' ),
			self::field( 'course_semester', 'tinyint unsigned' ),
			self::field( 'course_year', 'year' ),
			self::field( 'is_online', 'tinyint(1)', false, '0' ),
			self::field( 'course_description', 'varchar(1000)', true ),
			self::field( 'course_notes', 'varchar(1000)', true ),
			self::field( 'sections', 'int', true ),
			self::field( 'weekly_hours', 'int', true ),
			self::field( 'lab_hours', 'int', false, '0' ),
			self::field( 'enrollment', 'int', false, '0' ),
			self::field( 'max_enrollment', 'int', false, '0' ),
			self::field( 'professor_ufid', 'varchar(8)' ),
		);

		return array_column( $fields, null, 'Field' );
	}

	/**
	 * The professors table, as SHOW COLUMNS describes it
	 *
	 * @return array<string, array>
	 */
	private static function professor_fields(): array {
		$fields = array(
			self::field( 'professor_ufid', 'varchar(8)' ),
			self::field( 'professor_name', 'varchar(255)' ),
			self::field( 'professor_email', 'varchar(255)' ),
		);

		return array_column( $fields, null, 'Field' );
	}

	/**
	 * Every field the importer validates, as taas_import_courses() combines them
	 *
	 * @return array<string, array>
	 */
	private static function all_fields(): array {
		return self::course_fields() + self::professor_fields();
	}

	/**
	 * One valid spreadsheet row, keyed by header
	 *
	 * @param array $overrides Header => value to change.
	 * @return array<string, string>
	 */
	private static function row( array $overrides = array() ): array {
		$row = array(
			'Section'            => '12345',
			'Prefix'             => 'COP3502',
			'Title'              => 'Programming 1',
			'Semester'           => 'Fall',
			'Year'               => '2026',
			'UFO'                => 'In-person',
			'Description'        => 'Intro to programming',
			'Notes'              => 'Needs Python TAs',
			'Lab Hour'           => '2',
			'Current Enrollment' => '150',
			'Maximum Enrollment' => '200',
			'Professor Teaching' => 'Jane Doe',
			'GLID'               => 'jdoe@ufl.edu',
		);

		return array_merge( $row, $overrides );
	}

	/**
	 * Validate one row laid out in HEADERS order
	 *
	 * @param array $overrides Header => value to change.
	 * @return array{0: array|null, 1: array}
	 */
	private function parse( array $overrides = array() ): array {
		return taas_parse_course_row(
			array_values( self::row( $overrides ) ),
			taas_map_headers( self::HEADERS ),
			self::all_fields()
		);
	}

	/**
	 * Write the headers plus the given rows to a temporary .xlsx file
	 *
	 * The importer only reads from a file path, so its tests need a real file.
	 *
	 * @param array ...$rows Rows from row().
	 * @return string Path to the file
	 */
	private function write_courses( array ...$rows ): string {
		$spreadsheet = new Spreadsheet();
		$spreadsheet->getActiveSheet()->fromArray( array_merge( array( self::HEADERS ), array_map( 'array_values', $rows ) ) );

		$path          = sys_get_temp_dir() . '/taas-course-import-' . uniqid() . '.xlsx';
		$this->files[] = $path;

		( new Xlsx( $spreadsheet ) )->save( $path );
		$spreadsheet->disconnectWorksheets();

		return $path;
	}

	/**
	 * Headers are found in any order, keyed by column position, and unknown headers are ignored
	 */
	public function test_map_headers_in_any_order(): void {
		$map = taas_map_headers( array_merge( array( 'Room' ), array_reverse( self::HEADERS ) ) );

		$this->assertCount( count( self::HEADERS ), $map );
		$this->assertArrayNotHasKey( 0, $map );
		$this->assertSame( 'professor_email', $map[1]['schema_field'] );
		$this->assertSame( 'course_id', $map[13]['schema_field'] );
	}

	/**
	 * A missing required header throws, naming every one that is missing; optional ones may be left out
	 */
	public function test_map_headers_requires_required_headers_only(): void {
		taas_map_headers( array_diff( self::HEADERS, array( 'Description', 'Notes' ) ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Missing required headers: Year, GLID' );

		taas_map_headers( array_diff( self::HEADERS, array( 'Year', 'GLID' ) ) );
	}

	/**
	 * Each kind of field accepts only values that fit it
	 */
	public function test_cast_cell(): void {
		$fields = self::course_fields();

		$this->assertSame( 1, taas_cast_cell( 'Fall', $fields['course_semester'] ) );
		$this->assertSame( 2, taas_cast_cell( '2', $fields['course_semester'] ) );
		$this->assertNull( taas_cast_cell( 'Winter', $fields['course_semester'] ) );

		$this->assertSame( 1, taas_cast_cell( 'UFO', $fields['is_online'] ) );
		$this->assertSame( 0, taas_cast_cell( 'In-person', $fields['is_online'] ) );
		$this->assertNull( taas_cast_cell( 'Online', $fields['is_online'] ) );

		$this->assertSame( 150, taas_cast_cell( '150', $fields['enrollment'] ) );
		$this->assertNull( taas_cast_cell( '3.5', $fields['enrollment'] ) );
		$this->assertNull( taas_cast_cell( '2147483648', $fields['enrollment'] ) );

		$this->assertSame( 2026, taas_cast_cell( '2026', $fields['course_year'] ) );
		$this->assertNull( taas_cast_cell( '1900', $fields['course_year'] ) );

		$this->assertSame( 'COP3502', taas_cast_cell( 'COP3502', $fields['course_code'] ) );
		$this->assertNull( taas_cast_cell( str_repeat( 'a', 21 ), $fields['course_code'] ) );
	}

	/**
	 * A valid row comes back keyed by field, converted, with no messages
	 */
	public function test_parse_course_row_valid(): void {
		list( $data, $messages ) = $this->parse();

		$this->assertSame(
			array(
				'course_id'          => '12345',
				'course_code'        => 'COP3502',
				'course_name'        => 'Programming 1',
				'course_semester'    => 1,
				'course_year'        => 2026,
				'is_online'          => 0,
				'course_description' => 'Intro to programming',
				'course_notes'       => 'Needs Python TAs',
				'lab_hours'          => 2,
				'enrollment'         => 150,
				'max_enrollment'     => 200,
				'professor_name'     => 'Jane Doe',
				'professor_email'    => 'jdoe@ufl.edu',
			),
			$data
		);
		$this->assertSame( array(), $messages );
	}

	/**
	 * A missing or bad value in a field with no default skips the row, reporting every problem
	 */
	public function test_parse_course_row_skips_without_default(): void {
		list( $data, $messages ) = $this->parse(
			array(
				'Title'    => '',
				'Semester' => 'Winter',
			)
		);

		$this->assertNull( $data );
		$this->assertSame(
			array(
				array(
					'header'  => 'Title',
					'message' => 'Missing value, row skipped',
				),
				array(
					'header'  => 'Semester',
					'message' => 'Type mismatch, row skipped',
				),
			),
			$messages
		);
	}

	/**
	 * A missing or bad value in a field with a default (or that allows NULL) uses it and says so
	 */
	public function test_parse_course_row_uses_default(): void {
		list( $data, $messages ) = $this->parse(
			array(
				'UFO'      => 'Online',
				'Notes'    => '',
				'Lab Hour' => '',
			)
		);

		$this->assertSame( '0', $data['is_online'] );
		$this->assertNull( $data['course_notes'] );
		$this->assertSame( '0', $data['lab_hours'] );
		$this->assertSame(
			array(
				array(
					'header'  => 'UFO',
					'message' => 'Type mismatch, used default',
				),
				array(
					'header'  => 'Notes',
					'message' => 'Missing value, used default',
				),
				array(
					'header'  => 'Lab Hour',
					'message' => 'Missing value, used default',
				),
			),
			$messages
		);
	}

	/**
	 * Valid rows are saved with the professor's ufid; bad rows and unknown professors are skipped and reported
	 */
	public function test_import_saves_valid_rows_and_skips_bad_ones(): void {
		$result = taas_import_courses(
			$this->write_courses(
				self::row( array( 'Section' => '1' ) ),
				self::row(
					array(
						'Section' => '2',
						'Year'    => 'next year',
					)
				),
				self::row(
					array(
						'Section' => '3',
						'GLID'    => 'someone@ufl.edu',
					)
				)
			)
		);

		$this->assertSame( 1, $result['courses'] );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertSame(
			array(
				array(
					'row'     => 3,
					'header'  => 'Year',
					'message' => 'Type mismatch, row skipped',
				),
				array(
					'row'     => 4,
					'header'  => 'Professor Teaching',
					'message' => 'Professor not found, row skipped',
				),
			),
			$result['messages']
		);

		$this->assertCount( 1, $this->db->writes );
		$this->assertSame( '11111111', $this->db->writes[0]['data']['professor_ufid'] );
		$this->assertArrayNotHasKey( 'professor_name', $this->db->writes[0]['data'] );
		$this->assertArrayNotHasKey( 'professor_email', $this->db->writes[0]['data'] );
	}

	/**
	 * A Section seen again, in the database or later in the file, is replaced by the later row
	 */
	public function test_import_replaces_duplicate_section(): void {
		$result = taas_import_courses(
			$this->write_courses(
				self::row( array( 'Maximum Enrollment' => '200' ) ),
				self::row( array( 'Maximum Enrollment' => '250' ) )
			)
		);

		$this->assertSame( 1, $result['courses'] );
		$this->assertSame( 1, $result['replaced'] );
		$this->assertArrayHasKey( 'where', $this->db->writes[1] );
		$this->assertSame( 250, $this->db->writes[1]['data']['max_enrollment'] );
	}
}
