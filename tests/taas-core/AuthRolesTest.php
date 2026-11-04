<?php
/**
 * Tests for TAAS_Auth role resolution and UFID checks
 *
 * @package TAAS_Core
 */

use PHPUnit\Framework\TestCase;

/**
 * Check that every TAAS role a user holds counts, not just the highest
 */
class AuthRolesTest extends TestCase {

	/**
	 * Holding student and admin keeps both, highest first
	 */
	public function test_keeps_every_taas_role(): void {
		$this->assertSame(
			array( TAAS_Auth::ROLE_ADMIN, TAAS_Auth::ROLE_STUDENT ),
			TAAS_Auth::resolve_roles( array( TAAS_Auth::ROLE_STUDENT, TAAS_Auth::ROLE_ADMIN ) )
		);
	}

	/**
	 * The result is in priority order whatever order WordPress stores roles in
	 */
	public function test_orders_roles_by_priority(): void {
		$this->assertSame(
			array( TAAS_Auth::ROLE_HR, TAAS_Auth::ROLE_INSTRUCTOR, TAAS_Auth::ROLE_STUDENT ),
			TAAS_Auth::resolve_roles( array( TAAS_Auth::ROLE_STUDENT, TAAS_Auth::ROLE_INSTRUCTOR, TAAS_Auth::ROLE_HR ) )
		);
	}

	/**
	 * WordPress administrators hold every TAAS role without being assigned them
	 */
	public function test_wordpress_administrators_hold_every_taas_role(): void {
		$this->assertSame( TAAS_Auth::ROLE_PRIORITY, TAAS_Auth::resolve_roles( array( 'administrator' ) ) );
	}

	/**
	 * Saved TAAS roles on an administrator are not listed twice
	 */
	public function test_administrator_roles_are_listed_once(): void {
		$this->assertSame(
			TAAS_Auth::ROLE_PRIORITY,
			TAAS_Auth::resolve_roles( array( TAAS_Auth::ROLE_STUDENT, 'administrator', TAAS_Auth::ROLE_ADMIN ) )
		);
	}

	/**
	 * TAAS admin given in wp-admin is only TAAS admin; just WordPress administrators get everything
	 */
	public function test_taas_admin_alone_is_not_every_role(): void {
		$this->assertSame( array( TAAS_Auth::ROLE_ADMIN ), TAAS_Auth::resolve_roles( array( TAAS_Auth::ROLE_ADMIN ) ) );
	}

	/**
	 * Other WordPress roles give no TAAS access
	 */
	public function test_ignores_other_wordpress_roles(): void {
		$this->assertSame( array(), TAAS_Auth::resolve_roles( array( 'subscriber', 'editor' ) ) );
		$this->assertSame( array( TAAS_Auth::ROLE_STUDENT ), TAAS_Auth::resolve_roles( array( 'subscriber', TAAS_Auth::ROLE_STUDENT ) ) );
	}

	/**
	 * No roles at all resolves to none
	 */
	public function test_no_roles(): void {
		$this->assertSame( array(), TAAS_Auth::resolve_roles( array() ) );
	}

	/**
	 * Only eight digits count as a UFID
	 */
	public function test_valid_ufid(): void {
		$this->assertSame( '12345678', TAAS_Auth::valid_ufid( '12345678' ) );
		$this->assertSame( '12345678', TAAS_Auth::valid_ufid( ' 12345678 ' ) );
	}

	/**
	 * The Shibboleth plugin's "preset" meta, a login name or a bad length never pass as a UFID
	 */
	public function test_rejects_what_is_not_a_ufid(): void {
		foreach ( array( '', 'preset', 'weedguet.mildort', '1234567', '123456789', '1234567a', "12345678\n9" ) as $value ) {
			$this->assertSame( '', TAAS_Auth::valid_ufid( $value ), 'Accepted: ' . json_encode( $value ) );
		}
	}
}
