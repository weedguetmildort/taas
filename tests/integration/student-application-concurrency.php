<?php
/**
 * Real independent PHP/MySQL workers test save contention and failed-edit rollback.
 * Run: docker compose exec -T WordPress php /var/www/taas/tests/integration/student-application-concurrency.php
 * Uses committed, uniquely owned fixtures (workers must see them); deletes them in finally.
 *
 * @package TAAS_Tests
 */

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, WordPress.Security.EscapeOutput, WordPress.DB.DirectDatabaseQuery, WordPress.WP.GlobalVariablesOverride, WordPress.PHP.YodaConditions, Universal.Operators.StrictComparisons
// CLI checks intentionally use direct SQL, console diagnostics and JSON-semantic comparisons.

declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 ); }
require '/var/www/html/wp-load.php';
require __DIR__ . '/support/application-cycle.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development required.' ); }

function concurrency_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}

// Each worker boots WordPress separately and therefore has its own DB connection.
if ( '--worker' === ( $argv[1] ?? '' ) ) {
	$job = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
	file_put_contents( $job['directory'] . '/ready-' . $job['number'], 'ready' );
	$limit = microtime( true ) + 10;
	while ( ! file_exists( $job['directory'] . '/go' ) ) {
		if ( microtime( true ) > $limit ) {
			throw new RuntimeException( 'Worker barrier timed out.' ); }
		usleep( 10000 );
	}
	// Hold the first snapshot read briefly, after acquiring the student lock,
	// so the other independently running worker contends for that same lock.
	$held = false;
	add_filter(
		'query',
		static function ( $sql ) use ( &$held ) {
			if ( ! $held && str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, TAAS_Schema::table( 'student_submissions' ) ) ) {
				$held = true;
				usleep( 250000 );
			}
			return $sql;
		}
	);
	try {
		$input = TAAS_Student_Application_Input::parse( $job['body'], 'update' === $job['operation'] );
		$result = 'update' === $job['operation']
			? TAAS_Student_Application_Service::update( $job['owner'], $input, $job['revision'] )
			: TAAS_Student_Application_Service::submit( $job['owner'], $input );
		echo json_encode(
			array(
				'ok'     => true,
				'result' => $result,
			),
			JSON_THROW_ON_ERROR
		);
	} catch ( DomainException $error ) {
		echo json_encode(
			array(
				'ok'     => false,
				'status' => $error->getCode(),
			),
			JSON_THROW_ON_ERROR
		);
	}
	exit;
}

/**
 * Run two independent saves behind a common start barrier; always reap workers.
 *
 * @param string $directory Worker synchronization directory.
 * @param string $owner Fixture student identifier.
 * @param array $bodies Competing request payloads.
 * @param string $operation Save operation to exercise.
 * @param ?string $revision Expected saved revision token.
 */
function competing_saves( string $directory, string $owner, array $bodies, string $operation, ?string $revision = null ): array {
	$workers = array();
	try {
		foreach ( $bodies as $number => $body ) {
			$pipes = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Independent CLI workers are required to exercise real concurrent database connections.
			$process = proc_open(
				array( PHP_BINARY, __FILE__, '--worker' ),
				array(
					0 => array( 'pipe', 'r' ),
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Cannot start worker.' ); }
			$workers[] = array( $process, $pipes );
			fwrite(
				$pipes[0],
				json_encode(
					array(
						'directory' => $directory,
						'owner'     => $owner,
						'body'      => $body,
						'operation' => $operation,
						'revision'  => $revision,
						'number'    => $number,
					),
					JSON_THROW_ON_ERROR
				)
			);
			fclose( $pipes[0] );
		}
		$limit = microtime( true ) + 15;
		while ( ! file_exists( "$directory/ready-0" ) || ! file_exists( "$directory/ready-1" ) ) {
			if ( microtime( true ) > $limit ) {
				throw new RuntimeException( 'Workers did not reach the barrier.' ); }
			usleep( 10000 );
		}
		file_put_contents( "$directory/go", 'go' );
		do {
			$running = false;
			foreach ( $workers as [ $process ] ) {
				$running = proc_get_status( $process )['running'] || $running; }
			if ( microtime( true ) > $limit ) {
				throw new RuntimeException( 'Concurrent saves timed out.' ); }
			if ( $running ) {
				usleep( 10000 ); }
		} while ( $running );
		$results = array();
		foreach ( $workers as [ $process, $pipes ] ) {
			$output = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			if ( '' !== $error ) {
				throw new RuntimeException( 'Worker error: ' . $error ); }
			$results[] = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		}
		return $results;
	} finally {
		foreach ( $workers as [ $process, $pipes ] ) {
			if ( proc_get_status( $process )['running'] ) {
				proc_terminate( $process ); }
			foreach ( $pipes as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe ); }
			}
			proc_close( $process );
		}
		foreach ( glob( "$directory/*" ) as $file ) {
			unlink( $file ); }
	}
}

/**
 * Capture only the isolated fixture owner's state, including generated degree IDs.
 *
 * @param string $owner Fixture student identifier.
 */
function owner_state( string $owner ): array {
	global $wpdb;
	$state = array();
	foreach ( array( 'students', 'student_profiles', 'academic_background', 'applications', 'student_submissions' ) as $table ) {
		$state[ $table ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE student_ufid = %s', TAAS_Schema::table( $table ), $owner ), ARRAY_A );
	}
	return $state;
}

global $wpdb;
$students = new TAAS_Student_Repository();
$professors = new TAAS_Professor_Repository();
$owner = '';
$professor = 0;
$course_ids = array();
$directory = sys_get_temp_dir() . '/taas-concurrency-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $directory, 0700 ) ) {
	throw new RuntimeException( 'Cannot create barrier directory.' ); }
$period = array(
	'semester'  => 5,
	'year'      => 2155,
	'is_open'   => true,
	'closes_at' => null,
);
// Workers read the cycle from their own connections, so it is committed and restored in finally.
$cycles = new TAAS_Application_Cycle_Repository();
$original_cycle = $cycles->current();
try {
	taas_test_set_period( $period );
	do {
		$candidate = (string) random_int( 90000000, 99999999 );
	} while ( $students->find( $candidate ) );
	$students->insert( new TAAS_Student( $candidate, 'Concurrent fixture', 'fixture@ufl.edu' ) );
	$owner = $candidate;
	// Workers commit on their own connections, so a unique email keeps a crashed run's leftover row from blocking the next one.
	$professor = (int) $professors->insert( new TAAS_Professor( null, null, 'Concurrent fixture', 'fixture-' . bin2hex( random_bytes( 6 ) ) . '@example.invalid' ) );
	foreach ( array( 'A', 'B', 'C' ) as $letter ) {
		$id = (string) ( new TAAS_Course_Repository() )->insert( new TAAS_Course( null, 'RACE' . $letter, 'Concurrent fixture ' . bin2hex( random_bytes( 6 ) ), $professor, TAAS_Semester::Summer_C, 2155 ) );
		$course_ids[] = $id;
	}
	$body = array(
		'term'               => array(
			'semester' => 5,
			'year'     => 2155,
		),
		'details'            => array(
			'student_name'        => 'Concurrent fixture',
			'student_email'       => 'fixture@ufl.edu',
			'profile'             => array(
				'phone'             => '3525550100',
				'country_code'      => 'US',
				'admitted_semester' => 'Fall 2025',
				'available_hours'   => 10,
				'enrollment_status' => 2,
				'eap5836_status'    => 1,
				'eap5837_status'    => 4,
			),
			'academic_background' => array(
				array(
					'university'  => 'Fixture University',
					'major'       => 'CS',
					'degree_type' => 2,
					'grad_date'   => '2025-05-01',
					'gpa'         => 3.5,
				),
			),
		),
		'course_preferences' => array(
			array(
				'course_id'            => $course_ids[0],
				'rank'                 => 1,
				'has_completed_course' => true,
			),
		),
	);
	$results = competing_saves( $directory, $owner, array( $body, $body ), 'submit' );
	concurrency_check(
		$results[0]['ok'] && $results[1]['ok']
		&& $results[0]['result']['submission_id'] === $results[1]['result']['submission_id']
		&& $results[0]['result']['already_saved'] !== $results[1]['result']['already_saved'],
		'Concurrent identical submissions create once and recognize the retry'
	);
	$state = owner_state( $owner );
	concurrency_check( 1 === count( $state['student_submissions'] ) && 1 === count( $state['applications'] ), 'Concurrent creation leaves exactly one snapshot and course row' );


	// A different initial payload must conflict instead of replacing the first save.
	$wpdb->delete( TAAS_Schema::table( 'applications' ), array( 'student_ufid' => $owner ) );
	$wpdb->delete( TAAS_Schema::table( 'student_submissions' ), array( 'student_ufid' => $owner ) );
	$alternative = $body;
	$alternative['details']['student_name'] = 'Alternative initial submission';
	$alternative['details']['profile']['additional_info'] = 'Different initial payload';
	$initial_bodies = array( $body, $alternative );
	$results = competing_saves( $directory, $owner, $initial_bodies, 'submit' );
	$winner = $results[0]['ok'] ? 0 : 1;
	$state = owner_state( $owner );
	$initial_details = json_decode( $state['student_submissions'][0]['submitted_details'], true, 512, JSON_THROW_ON_ERROR );
	concurrency_check(
		$results[ $winner ]['ok'] && ! $results[ 1 - $winner ]['ok'] && 409 === $results[ 1 - $winner ]['status']
		&& 1 === count( $state['student_submissions'] ) && 1 === count( $state['applications'] )
		&& $initial_details == TAAS_Student_Application_Input::parse( $initial_bodies[ $winner ] )['details']
		&& $state['students'][0]['student_name'] === $initial_details['student_name'],
		'Concurrent different initial submissions preserve one winner and reject the conflict'
	);

	$submissions = new TAAS_Student_Submission_Repository();
	$saved = $submissions->find_application_for_term( $owner, TAAS_Semester::Summer_C, 2155 );
	$revision = TAAS_Student_Application_Service::revision( $saved['snapshot'], $saved['choices'] );
	$edits = array();
	foreach ( array( 'A', 'B' ) as $index => $letter ) {
		$edit = $body;
		$edit['details']['student_name'] = 'Winner ' . $letter;
		$edit['details']['profile']['phone'] = '352555010' . $index;
		$edit['details']['academic_background'][0]['university'] = 'University ' . $letter;
		$edit['course_preferences'][0]['course_id'] = $course_ids[ $index + 1 ];
		$edits[] = $edit;
	}
	$results = competing_saves( $directory, $owner, $edits, 'update', $revision );
	$winner = $results[0]['ok'] ? 0 : 1;
	concurrency_check( $results[ $winner ]['ok'] && ! $results[ 1 - $winner ]['ok'] && 409 === $results[ 1 - $winner ]['status'], 'Concurrent edits with one revision allow exactly one winner' );
	$state = owner_state( $owner );
	$expected = TAAS_Student_Application_Input::parse( $edits[ $winner ], true );
	$saved = $submissions->find_application_for_term( $owner, TAAS_Semester::Summer_C, 2155 );
	$active = array_values( array_filter( $state['applications'], static fn( $row ) => 1 === (int) $row['match_status'] ) );
	concurrency_check(
		$saved['snapshot']->submitted_details == $expected['details']
		&& $state['students'][0]['student_name'] === $expected['details']['student_name']
		&& $state['student_profiles'][0]['phone'] === $expected['details']['profile']['phone']
		&& $state['academic_background'][0]['university'] === $expected['details']['academic_background'][0]['university']
		&& 1 === count( $active ) && $active[0]['applied_course_id'] === $expected['course_preferences'][0]['course_id']
		&& $results[ $winner ]['result']['revision'] === TAAS_Student_Application_Service::revision( $saved['snapshot'], $saved['choices'] ),
		'Losing edit cannot change the winner identity, profile, degrees, choices, or revision'
	);

	// Force failure at the final snapshot write after all earlier edit writes ran.
	$before = owner_state( $owner );
	$failed = $edits[ 1 - $winner ];
	$input = TAAS_Student_Application_Input::parse( $failed, true );
	$fail = static fn( $sql ) => str_contains( $sql, TAAS_Schema::table( 'student_submissions' ) ) && preg_match( '/^\\s*INSERT INTO/i', $sql ) ? 'INVALID SQL FOR EDIT ROLLBACK TEST' : $sql;
	add_filter( 'query', $fail );
	$previous_errors = $wpdb->suppress_errors( true );
	try {
		TAAS_Student_Application_Service::update( $owner, $input, TAAS_Student_Application_Service::revision( $saved['snapshot'], $saved['choices'] ) );
		throw new LogicException( 'Expected snapshot failure.' );
	} catch ( RuntimeException $error ) {
		concurrency_check( str_contains( $error->getMessage(), 'Saving student submission failed' ), 'Failed edit surfaces the injected snapshot failure' );
	} finally {
		remove_filter( 'query', $fail );
		$wpdb->suppress_errors( $previous_errors );
	}
	concurrency_check( $before === owner_state( $owner ), 'Failed edit rolls back identity, profile, degree IDs, preferences, and snapshot' );
} finally {
	null === $original_cycle ? $cycles->delete( TAAS_Application_Cycle::CURRENT_ID ) : $cycles->save( $original_cycle );
	// Only delete rows belonging to IDs created successfully by this test.
	if ( '' !== $owner ) {
		foreach ( array( 'applications', 'student_submissions', 'academic_background', 'student_profiles', 'students' ) as $table ) {
			if ( false === $wpdb->delete( TAAS_Schema::table( $table ), array( 'student_ufid' => $owner ) ) ) {
				throw new RuntimeException( 'Fixture cleanup failed: ' . $table ); }
		}
	}
	foreach ( $course_ids as $id ) {
		$wpdb->delete( TAAS_Schema::table( 'courses' ), array( 'course_id' => $id ) ); }
	if ( 0 !== $professor ) {
		$professors->delete( $professor ); }
	foreach ( glob( "$directory/*" ) as $file ) {
		unlink( $file ); }
	rmdir( $directory );
}
concurrency_check( ! $students->find( $owner ) && ! $professors->find( $professor ), 'Isolated concurrency fixtures cleaned up' );
