<?php
/**
 * Tests for who may see UFIDs in REST responses
 *
 * @package TAAS_Core
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, WordPress.Security.NonceVerification

use PHPUnit\Framework\TestCase;

// TAAS_Auth's mock user lets a test pick the caller's role through ?taas_role=.
if ( ! defined( 'TAAS_LOCAL_DEV' ) ) {
	define( 'TAAS_LOCAL_DEV', true );
}

require_once dirname( __DIR__ ) . '/support/class-wp-rest-controller.php';
require_once dirname( __DIR__ ) . '/support/class-wp-rest-response.php';

/**
 * Check UFID columns reach HR and admins only
 */
class UfidVisibilityTest extends TestCase {

	/**
	 * Clear the role override
	 */
	protected function tearDown(): void {
		unset( $_GET['taas_role'] );
	}

	/**
	 * Build a row object holding a UFID
	 *
	 * @param string $column UFID column name.
	 * @return object
	 */
	private static function item( string $column ): object {
		return new class( $column ) {
			public function __construct( private string $column ) {}

			public function to_row(): array {
				return array(
					'name'        => 'visible',
					$this->column => '12345678',
				);
			}

			public function grad_label(): string {
				return '05/2026';
			}
		};
	}

	/**
	 * Students and instructors never get a UFID, even from a route they can read
	 */
	public function test_students_and_instructors_do_not_see_ufids(): void {
		foreach ( array( 'student', 'instructor' ) as $role ) {
			$_GET['taas_role'] = $role;

			$data = ( new TAAS_REST_Course_Controller() )
				->prepare_item_for_response( self::item( 'professor_ufid' ), null )
				->get_data();

			$this->assertSame( array( 'name' => 'visible' ), $data, $role );
		}
	}

	/**
	 * HR and admins get the UFID
	 */
	public function test_hr_and_admins_see_ufids(): void {
		foreach ( array( 'hr', 'admin' ) as $role ) {
			$_GET['taas_role'] = $role;

			$data = ( new TAAS_REST_Course_Controller() )
				->prepare_item_for_response( self::item( 'professor_ufid' ), null )
				->get_data();

			$this->assertSame( '12345678', $data['professor_ufid'], $role );
		}
	}

	/**
	 * Enums go out as labels and booleans as true or false, never as stored integers
	 *
	 * @dataProvider typed_rows
	 *
	 * @param object $row      Row object.
	 * @param array  $expected Column => value expected in the response.
	 */
	public function test_responses_never_carry_raw_enums( object $row, array $expected ): void {
		$_GET['taas_role'] = 'admin';

		$data = ( new TAAS_REST_Course_Controller() )->prepare_item_for_response( $row, null )->get_data();

		foreach ( $expected as $column => $value ) {
			$this->assertSame( $value, $data[ $column ], $column );
		}
	}

	/**
	 * One row of every table with an enum or boolean column
	 *
	 * @return array<string, array>
	 */
	public static function typed_rows(): array {
		return array(
			'course'              => array(
				new TAAS_Course( 1, 'COP3502', 'Programming 1', '87654321', TAAS_Semester::Summer_A, 2027, true ),
				array(
					'course_semester' => 'Summer A',
					'is_online'       => true,
				),
			),
			'application'         => array(
				new TAAS_Application( 1, '12345678', 1, true, false, null, null, null, TAAS_Match_Status::Matched, 20 ),
				array(
					'match_status'      => 'Matched',
					'has_taken_course'  => true,
					'has_ta_for_course' => false,
				),
			),
			'student profile'     => array(
				new TAAS_Student_Profile(
					'12345678',
					enrollment_status: TAAS_Enrollment_Status::Phd,
					has_ta_experience: true,
					eap5836_status: TAAS_Course_Taken_Status::Taken_At_UF,
					english_test_score: TAAS_English_Test_Score::Middle_Range,
				),
				array(
					'enrollment_status'  => 'PhD',
					'has_ta_experience'  => true,
					'eap5836_status'     => 'Taken at UF',
					'eap5837_status'     => null,
					'english_test_score' => '45–54 SPEAK / 23–27 TOEFL iBT',
				),
			),
			'academic background' => array(
				new TAAS_Academic_Background( 1, '12345678', 'UF', TAAS_Degree_Type::MS, new DateTimeImmutable( '2026-05-01' ) ),
				array( 'degree_type' => 'MS' ),
			),
		);
	}

	/**
	 * A controller with its own response shape follows the same rule
	 */
	public function test_academic_background_override_follows_the_rule(): void {
		$controller = new TAAS_REST_Academic_Background_Controller();
		$item       = self::item( 'student_ufid' );

		$_GET['taas_role'] = 'student';
		$this->assertArrayNotHasKey( 'student_ufid', $controller->prepare_item_for_response( $item, null )->get_data() );

		$_GET['taas_role'] = 'admin';
		$this->assertArrayHasKey( 'student_ufid', $controller->prepare_item_for_response( $item, null )->get_data() );
	}
}
