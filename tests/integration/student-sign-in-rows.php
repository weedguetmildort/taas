<?php
/**
 * Verify signed-in students get a students row, from sign-in and from the backfill.
 * Run with PHP in the WordPress container: /var/www/taas/tests/integration/student-sign-in-rows.php
 * The backfill uses tables under a random prefix, dropped afterwards. The form
 * checks run in a transaction that is rolled back.
 *
 * @package TAAS_Tests
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery,WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride, WordPress.Security.EscapeOutput

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
require __DIR__ . '/support/application-cycle.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development mode required.' );
}

$check = static function ( bool $passed, string $message ): void {
	if ( ! $passed ) {
		throw new RuntimeException( $message );
	}
};

$original_prefix = $wpdb->prefix;
$sources         = array(
	'users'    => $wpdb->users,
	'usermeta' => $wpdb->usermeta,
	'students' => TAAS_Schema::table( 'students' ),
);
$test_prefix     = 'sign_in_test_' . bin2hex( random_bytes( 4 ) ) . '_';
$migrate         = require TAAS_CORE_PATH . 'migrations/005-add-signed-in-students.php';
$created         = array();

try {
	$wpdb->set_prefix( $test_prefix );
	$students = TAAS_Schema::table( 'students' );

	// Before any WordPress table exists, as in the schema upgrade check
	$wpdb->query( "CREATE TABLE {$students} LIKE {$sources['students']}" );
	$created[] = $students;
	$migrate( $wpdb );
	$check( '0' === $wpdb->get_var( "SELECT COUNT(*) FROM {$students}" ), 'The backfill added rows without a users table.' );

	foreach ( array( 'users', 'usermeta' ) as $table ) {
		$wpdb->query( "CREATE TABLE {$wpdb->$table} LIKE {$sources[ $table ]}" );
		$created[] = $wpdb->$table;
	}

	$caps    = $wpdb->get_blog_prefix() . 'capabilities';
	$account = static function ( string $login, string $display, array $roles, array $meta ) use ( $wpdb, $caps ): void {
		$wpdb->insert(
			$wpdb->users,
			array(
				'user_login'      => $login,
				'user_pass'       => '',
				'user_nicename'   => $login,
				'user_email'      => $login . '@ufl.edu',
				'user_registered' => '2026-01-01 00:00:00',
				'display_name'    => $display,
			)
		);
		$id   = $wpdb->insert_id;
		$meta = array( $caps => serialize( array_fill_keys( $roles, true ) ) ) + $meta; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores roles serialized.
		foreach ( $meta as $key => $value ) {
			$wpdb->insert(
				$wpdb->usermeta,
				array(
					'user_id'    => $id,
					'meta_key'   => $key,
					'meta_value' => $value,
				)
			);
		}
	};

	// Display name left as the login, so the name comes from first and last name
	$account(
		'albert.gator',
		'albert.gator',
		array( 'subscriber', TAAS_Auth::ROLE_STUDENT ),
		array(
			TAAS_Auth::UFID_META_KEY => '10000001',
			'first_name'             => 'Albert',
			'last_name'              => 'Gator',
		)
	);
	// Already has a row with a name they corrected
	$account( 'alberta.gator', 'Alberta Gator', array( TAAS_Auth::ROLE_STUDENT ), array( TAAS_Auth::UFID_META_KEY => '10000002' ) );
	$wpdb->insert(
		$students,
		array(
			'student_ufid'  => '10000002',
			'student_name'  => 'Alberta G. Gator',
			'student_email' => 'preferred@ufl.edu',
		)
	);
	// Not students, or no usable UFID
	$account( 'prof.gator', 'Prof Gator', array( TAAS_Auth::ROLE_INSTRUCTOR ), array( TAAS_Auth::UFID_META_KEY => '10000003' ) );
	$account( 'new.gator', 'New Gator', array( TAAS_Auth::ROLE_STUDENT ), array() );
	$account( 'preset.gator', 'Preset Gator', array( TAAS_Auth::ROLE_STUDENT ), array( TAAS_Auth::UFID_META_KEY => 'preset' ) );

	$migrate( $wpdb );
	$migrate( $wpdb );

	$rows = $wpdb->get_results( "SELECT student_ufid, student_name, student_email FROM {$students} ORDER BY student_ufid", ARRAY_A );
	$check(
		array(
			array(
				'student_ufid'  => '10000001',
				'student_name'  => 'Albert Gator',
				'student_email' => 'albert.gator@ufl.edu',
			),
			array(
				'student_ufid'  => '10000002',
				'student_name'  => 'Alberta G. Gator',
				'student_email' => 'preferred@ufl.edu',
			),
		) === $rows,
		'The backfill rows are wrong: ' . wp_json_encode( $rows )
	);

	// Sign-in uses the same write: added once, then left alone
	$repository = new TAAS_Student_Repository();
	$check( true === $repository->add_if_missing( new TAAS_Student( '10000009', 'First Name', 'first@ufl.edu' ) ), 'A new student was not reported as added.' );
	$check( false === $repository->add_if_missing( new TAAS_Student( '10000009', 'Second Name', 'second@ufl.edu' ) ), 'An existing student was reported as added.' );
	$check(
		array(
			'student_name'  => 'First Name',
			'student_email' => 'first@ufl.edu',
		) === $wpdb->get_row( "SELECT student_name, student_email FROM {$students} WHERE student_ufid = '10000009'", ARRAY_A ),
		'A later sign-in changed an existing row.'
	);

} finally {
	foreach ( array_reverse( $created ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
	$wpdb->set_prefix( $original_prefix );
}

// The application form starts from the students row, then from the account
$_GET['taas_role'] = 'student';
do_action( 'rest_api_init' );
$form_name = static function (): string {
	$response = rest_do_request( new WP_REST_Request( 'GET', '/taas/v1/student/application' ) );
	if ( 200 !== $response->get_status() ) {
		throw new RuntimeException( 'Loading the application form failed: ' . wp_json_encode( $response->get_data() ) );
	}
	return (string) $response->get_data()['identity']['name'];
};
$owner    = TAAS_Auth::current_user()['university_id'];
$sentinel = new RuntimeException( 'Roll back the form fixtures.' );

try {
	// Repositories nest in this transaction, so the rollback undoes their writes too
	TAAS_Transaction::run(
		static function () use ( $wpdb, $check, $form_name, $owner, $sentinel ): void {
			taas_test_set_period(
				array(
					'semester' => TAAS_Semester::Fall->value,
					'year'     => 2026,
					'is_open'  => true,
				)
			);
			$wpdb->delete( TAAS_Schema::table( 'students' ), array( 'student_ufid' => $owner ) );
			$check( TAAS_Auth::current_user()['display_name'] === $form_name(), 'Without a students row, the form did not start from the account name.' );

			( new TAAS_Student_Repository() )->add_if_missing( new TAAS_Student( $owner, 'Corrected Name', 'test.student@ufl.edu' ) );
			$check( 'Corrected Name' === $form_name(), 'The form did not start from the students row.' );

			throw $sentinel;
		}
	);
} catch ( RuntimeException $e ) {
	if ( $e !== $sentinel ) {
		throw $e;
	}
}

echo "Student sign-in rows passed.\n";
