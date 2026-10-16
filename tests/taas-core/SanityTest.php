<?php
/**
 * Sanity test for the CI pipeline
 *
 * @package TaaS_Core
 */

use PHPUnit\Framework\TestCase;

/**
 * Check sanity
 */
class SanityTest extends TestCase {

	/**
	 * Confirm the test runner is wired up
	 */
	public function test_phpunit_is_running(): void {
		$this->assertTrue( true );
	}
}
