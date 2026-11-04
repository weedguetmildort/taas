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
	 * Only the last two digits of a UFID are shown
	 */
	public function test_mask_ufid(): void {
		$this->assertSame( '******78', TAAS_Shibboleth::mask_ufid( '12345678' ) );
		$this->assertSame( '', TAAS_Shibboleth::mask_ufid( 'preset' ) );
	}
}
