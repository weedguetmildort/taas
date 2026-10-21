<?php
/**
 * Database transaction helper.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * All-or-nothing writes across more than one repository.
 *
 * Each repository call is its own statement, so a request that writes to
 * several tables can fail halfway and leave a partial record. Wrap those calls:
 *
 *   TAAS_Transaction::run(
 *       function () use ( $students, $profiles, $student, $profile ) {
 *           $students->insert( $student );
 *           $profiles->insert( $profile );
 *       }
 *   );
 *
 * If the callback throws, everything it wrote is rolled back and the exception
 * is rethrown, so a controller's respond() still turns it into a logged 500.
 * Requires InnoDB tables, which MySQL 8 uses by default and the foreign keys
 * need anyway.
 *
 * Calls nest by joining the outer transaction: only the outermost run() commits
 * or rolls back.
 */
final class TAAS_Transaction {

	/**
	 * How many run() calls are currently open.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Run a callback inside a transaction.
	 *
	 * @param callable $work Callback that does the writes.
	 * @return mixed Whatever the callback returns.
	 * @throws RuntimeException If the transaction cannot be started or committed.
	 * @throws Throwable        Anything the callback throws, after rollback.
	 */
	public static function run( callable $work ): mixed {
		global $wpdb;

		if ( self::$depth > 0 ) {
			++self::$depth;

			try {
				return $work();
			} finally {
				--self::$depth;
			}
		}

		self::execute( 'START TRANSACTION' );
		self::$depth = 1;

		try {
			$result = $work();
			self::execute( 'COMMIT' );

			return $result;
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			throw $e;
		} finally {
			self::$depth = 0;
		}
	}

	/**
	 * Run one transaction-control statement.
	 *
	 * @param string $statement START TRANSACTION or COMMIT.
	 * @return void
	 * @throws RuntimeException If the statement fails.
	 */
	private static function execute( string $statement ): void {
		global $wpdb;

		if ( false === $wpdb->query( $statement ) ) {
			throw new RuntimeException( esc_html( $statement . ' failed: ' . $wpdb->last_error ) );
		}
	}
}
