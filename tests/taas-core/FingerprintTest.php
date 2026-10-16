<?php
/**
 * Tests for TAAS_DB::fingerprint()
 *
 * @package TAAS_Core
 */

use PHPUnit\Framework\TestCase;

/**
 * Check that the fingerprint changes exactly when the installer's inputs do
 */
class FingerprintTest extends TestCase {

	/**
	 * Temporary plugin root for each test
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Build a minimal plugin tree: two SQL files, one migration, one page
	 */
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/taas-fingerprint-' . uniqid();

		foreach ( array( 'sql', 'migrations', 'pages' ) as $dir ) {
			mkdir( $this->root . '/' . $dir, 0777, true );
		}

		$this->write( 'sql/schema.sql', 'CREATE TABLE {prefix}students ( id int );' );
		$this->write( 'sql/constraints.sql', '' );
		$this->write( 'migrations/001-first.php', '<?php return static function () {};' );
		$this->write( 'pages/hr-page.html', '<p>HR</p>' );
	}

	/**
	 * Remove the temporary plugin tree
	 */
	protected function tearDown(): void {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $this->root );
	}

	/**
	 * Write a file relative to the temporary plugin root
	 *
	 * @param string $file Relative path
	 * @param string $contents File contents
	 */
	private function write( string $file, string $contents ): void {
		file_put_contents( $this->root . '/' . $file, $contents );
	}

	/**
	 * Same files, same fingerprint, with or without a trailing slash
	 */
	public function test_is_stable(): void {
		$this->assertSame( TAAS_DB::fingerprint( $this->root ), TAAS_DB::fingerprint( $this->root . '/' ) );
	}

	/**
	 * A new migration is the case the version gate used to miss
	 */
	public function test_changes_when_a_migration_is_added(): void {
		$before = TAAS_DB::fingerprint( $this->root );
		$this->write( 'migrations/002-second.php', '<?php return static function () {};' );

		$this->assertNotSame( $before, TAAS_DB::fingerprint( $this->root ) );
	}

	/**
	 * A new column in schema.sql must trigger dbDelta
	 */
	public function test_changes_when_schema_changes(): void {
		$before = TAAS_DB::fingerprint( $this->root );
		$this->write( 'sql/schema.sql', 'CREATE TABLE {prefix}students ( id int, term varchar(20) );' );

		$this->assertNotSame( $before, TAAS_DB::fingerprint( $this->root ) );
	}

	/**
	 * A new foreign key or check must be applied
	 */
	public function test_changes_when_constraints_change(): void {
		$before = TAAS_DB::fingerprint( $this->root );
		$this->write( 'sql/constraints.sql', 'ALTER TABLE {prefix}x ADD CONSTRAINT chk_x CHECK ( id > 0 );' );

		$this->assertNotSame( $before, TAAS_DB::fingerprint( $this->root ) );
	}

	/**
	 * A new page file means a new page to create
	 */
	public function test_changes_when_a_page_is_added(): void {
		$before = TAAS_DB::fingerprint( $this->root );
		$this->write( 'pages/admin-page.html', '<p>Admin</p>' );

		$this->assertNotSame( $before, TAAS_DB::fingerprint( $this->root ) );
	}

	/**
	 * Migrations run once by filename, so editing one that ran changes nothing
	 */
	public function test_ignores_edits_to_an_existing_migration(): void {
		$before = TAAS_DB::fingerprint( $this->root );
		$this->write( 'migrations/001-first.php', '<?php return static function () { /* edited */ };' );

		$this->assertSame( $before, TAAS_DB::fingerprint( $this->root ) );
	}

	/**
	 * Files the installer never reads do not trigger it
	 */
	public function test_ignores_unrelated_files(): void {
		$before = TAAS_DB::fingerprint( $this->root );
		$this->write( 'migrations/README.md', '# Notes' );

		$this->assertSame( $before, TAAS_DB::fingerprint( $this->root ) );
	}
}
