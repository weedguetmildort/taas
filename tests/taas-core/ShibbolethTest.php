<?php
/**
 * Tests for the pure helpers in TAAS_Shibboleth
 *
 * @package TAAS_Core
 */

use PHPUnit\Framework\TestCase;

/**
 * Check role syncing, session matching and attribute parsing
 */
class ShibbolethTest extends TestCase {

	/**
	 * A professor without the instructor role gains it
	 */
	public function test_adds_a_role_that_should_be_held(): void {
		$this->assertSame(
			array(
				'add'    => array( TAAS_Auth::ROLE_INSTRUCTOR ),
				'remove' => array(),
			),
			TAAS_Shibboleth::role_changes( array( 'subscriber' ), array( TAAS_Auth::ROLE_INSTRUCTOR => true ) )
		);
	}

	/**
	 * Someone no longer in the professors table loses the instructor role
	 */
	public function test_removes_a_role_that_should_not_be_held(): void {
		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array( TAAS_Auth::ROLE_INSTRUCTOR ),
			),
			TAAS_Shibboleth::role_changes( array( 'subscriber', TAAS_Auth::ROLE_INSTRUCTOR ), array( TAAS_Auth::ROLE_INSTRUCTOR => false ) )
		);
	}

	/**
	 * Roles without a source, such as HR and admin, are never touched
	 */
	public function test_leaves_unsynced_roles_alone(): void {
		$current = array( 'administrator', TAAS_Auth::ROLE_HR, TAAS_Auth::ROLE_ADMIN, TAAS_Auth::ROLE_STUDENT );

		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array(),
			),
			TAAS_Shibboleth::role_changes( $current, array() )
		);
	}

	/**
	 * Nothing changes when the account already matches
	 */
	public function test_no_changes_when_already_in_sync(): void {
		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array(),
			),
			TAAS_Shibboleth::role_changes( array( TAAS_Auth::ROLE_INSTRUCTOR ), array( TAAS_Auth::ROLE_INSTRUCTOR => true ) )
		);
	}

	/**
	 * The session matches an account named after the GatorLink ID, in any case
	 */
	public function test_session_matches_by_gatorlink_id(): void {
		$this->assertTrue( TAAS_Shibboleth::session_matches( 'albert.gator', 'other@ufl.edu', 'Albert.Gator', 'albert.gator@ufl.edu' ) );
	}

	/**
	 * An older account combined by email matches on email
	 */
	public function test_session_matches_by_email(): void {
		$this->assertTrue( TAAS_Shibboleth::session_matches( 'agator', 'Albert.Gator@ufl.edu', 'albert.gator', 'albert.gator@ufl.edu' ) );
	}

	/**
	 * Someone else's Shibboleth session never updates this account
	 */
	public function test_session_for_someone_else_does_not_match(): void {
		$this->assertFalse( TAAS_Shibboleth::session_matches( 'albert.gator', 'albert.gator@ufl.edu', 'alberta.gator', 'alberta.gator@ufl.edu' ) );
	}

	/**
	 * Missing attributes never match, even against an account with empty fields
	 */
	public function test_empty_attributes_do_not_match(): void {
		$this->assertFalse( TAAS_Shibboleth::session_matches( '', '', '', '' ) );
		$this->assertFalse( TAAS_Shibboleth::session_matches( 'albert.gator', 'albert.gator@ufl.edu', '', '' ) );
	}

	/**
	 * Multi-valued attributes split on semicolons, without blanks or repeats
	 */
	public function test_parse_values(): void {
		$this->assertSame( array( 'member', 'student', 'employee' ), TAAS_Shibboleth::parse_values( 'member; student;;employee;student' ) );
		$this->assertSame( array(), TAAS_Shibboleth::parse_values( '' ) );
		$this->assertSame( array( '0' ), TAAS_Shibboleth::parse_values( '0' ) );
	}

	/**
	 * UF's "$"-terminated values and Shibboleth's ";" both separate affiliations
	 */
	public function test_affiliations_normalize_uf_format(): void {
		$this->assertSame( array( 'student' ), TAAS_Shibboleth::affiliations( 'STUDENT$' ) );
		$this->assertSame( array( 'student', 'employee' ), TAAS_Shibboleth::affiliations( 'STUDENT$EMPLOYEE$' ) );
		$this->assertSame( array( 'member', 'student' ), TAAS_Shibboleth::affiliations( 'member; Student;STUDENT$' ) );
		$this->assertSame( array(), TAAS_Shibboleth::affiliations( '' ) );
		$this->assertSame( array(), TAAS_Shibboleth::affiliations( ' $ ; ' ) );
	}

	/**
	 * The value UF sends for a student, alone or with others, makes them a student
	 */
	public function test_student_affiliation_is_a_student(): void {
		$this->assertTrue( TAAS_Shibboleth::is_student( 'STUDENT$' ) );
		$this->assertTrue( TAAS_Shibboleth::is_student( 'EMPLOYEE$STUDENT$' ) );
		$this->assertTrue( TAAS_Shibboleth::is_student( 'member;student' ) );
	}

	/**
	 * Only a whole value counts, so a former student is not a student
	 */
	public function test_other_affiliations_are_not_a_student(): void {
		$this->assertFalse( TAAS_Shibboleth::is_student( 'FACULTY$' ) );
		$this->assertFalse( TAAS_Shibboleth::is_student( 'FORMER_STUDENT$' ) );
		$this->assertFalse( TAAS_Shibboleth::is_student( 'STUDENTS$' ) );
	}

	/**
	 * A missing attribute is unknown, so the student role is left alone
	 */
	public function test_missing_affiliations_are_unknown(): void {
		$this->assertNull( TAAS_Shibboleth::is_student( '' ) );
		$this->assertNull( TAAS_Shibboleth::is_student( '$' ) );
	}

	/**
	 * The values that count as student can be changed
	 */
	public function test_student_values_can_be_overridden(): void {
		$this->assertTrue( TAAS_Shibboleth::is_student( 'GRAD_STUDENT$', array( 'student', 'grad_student' ) ) );
	}

	/**
	 * A student loses the role when the affiliation goes, and an unknown affiliation keeps it
	 */
	public function test_student_role_follows_the_affiliation(): void {
		$holds = array( 'subscriber', TAAS_Auth::ROLE_STUDENT );

		$this->assertSame( array( TAAS_Auth::ROLE_STUDENT ), TAAS_Shibboleth::role_changes( $holds, array( TAAS_Auth::ROLE_STUDENT => false ) )['remove'] );
		$this->assertSame( array( TAAS_Auth::ROLE_STUDENT ), TAAS_Shibboleth::role_changes( array( 'subscriber' ), array( TAAS_Auth::ROLE_STUDENT => true ) )['add'] );
		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array(),
			),
			TAAS_Shibboleth::role_changes( $holds, array() )
		);
	}

	/**
	 * A real display name is used as is, with spacing tidied
	 */
	public function test_student_name_prefers_the_display_name(): void {
		$this->assertSame( 'Albert Gator', TAAS_Shibboleth::student_name( '  Albert   Gator ', 'albert.gator', 'Albert', 'Gator-Smith' ) );
	}

	/**
	 * WordPress's default display name, the login, falls back to first and last name
	 */
	public function test_student_name_skips_a_display_name_that_is_the_login(): void {
		$this->assertSame( 'Albert Gator', TAAS_Shibboleth::student_name( 'Albert.Gator', 'albert.gator', 'Albert', 'Gator' ) );
		$this->assertSame( 'Albert Gator', TAAS_Shibboleth::student_name( '', 'albert.gator', 'Albert', 'Gator' ) );
		$this->assertSame( 'Gator', TAAS_Shibboleth::student_name( '', 'albert.gator', '', 'Gator' ) );
	}

	/**
	 * With no name known, the student fills it in on the application
	 */
	public function test_student_name_can_be_empty(): void {
		$this->assertSame( '', TAAS_Shibboleth::student_name( 'albert.gator', 'albert.gator', '', '' ) );
	}

	/**
	 * Names longer than the column are cut to fit
	 */
	public function test_student_name_fits_the_column(): void {
		$this->assertSame( 255, mb_strlen( TAAS_Shibboleth::student_name( str_repeat( 'é', 300 ), 'albert.gator', '', '' ) ) );
	}

	/**
	 * The account email comes first, since applications must match it
	 */
	public function test_student_email_prefers_the_account_email(): void {
		$this->assertSame( 'albert@ufl.edu', TAAS_Shibboleth::student_email( ' albert@ufl.edu ', array( 'other@ufl.edu' ) ) );
		$this->assertSame( 'other@ufl.edu', TAAS_Shibboleth::student_email( '', array( '', 'other@ufl.edu' ) ) );
		$this->assertSame( '', TAAS_Shibboleth::student_email( '', array() ) );
	}

	/**
	 * Only the last two digits of a UFID are shown
	 */
	public function test_mask_ufid(): void {
		$this->assertSame( '******78', TAAS_Shibboleth::mask_ufid( '12345678' ) );
		$this->assertSame( '', TAAS_Shibboleth::mask_ufid( 'preset' ) );
	}
}
