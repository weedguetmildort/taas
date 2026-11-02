<?php
/**
 * Parser boundary checks; no application data is written.
 *
 * @package TAAS_Tests
 */

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, WordPress.Security.EscapeOutput, WordPress.DB.DirectDatabaseQuery, WordPress.WP.GlobalVariablesOverride, WordPress.PHP.YodaConditions, Universal.Operators.StrictComparisons
// CLI checks intentionally use direct SQL, console diagnostics and JSON-semantic comparisons.

declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 ); }
require '/var/www/html/wp-load.php';

function taas_input_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
}

/**
 * Every rejection must be a validation exception, rather than a PHP runtime error.
 *
 * @param array $body Decoded request body.
 * @param string $message Failure description.
 */
function taas_input_rejects( array $body, string $message ): void {
	try {
		TAAS_Student_Application_Input::parse( $body );
	} catch ( InvalidArgumentException $error ) {
		return;
	}
	throw new RuntimeException( $message );
}

$body = array(
	'term'               => array(
		'semester' => 1,
		'year'     => 2026,
	),
	'details'            => array(
		'student_name'        => ' Test Student ',
		'student_email'       => 'student@ufl.edu',
		'profile'             => array(
			'phone'             => '3525550100',
			'country_code'      => 'us',
			'admitted_semester' => 'Fall 2025',
			'available_hours'   => 10,
			'enrollment_status' => 2,
			'eap5836_status'    => 1,
			'eap5837_status'    => 4,
		),
		'academic_background' => array(
			array(
				'university'  => 'Example University',
				'degree_type' => 2,
				'major'       => 'CS',
				'grad_date'   => '2025-05-01',
				'gpa'         => 3.5,
			),
		),
	),
	'course_preferences' => array(
		array(
			'course_id'            => '101',
			'rank'                 => 1,
			'has_completed_course' => true,
		),
	),
);
$parsed = TAAS_Student_Application_Input::parse( $body );
taas_input_check( 'Test Student' === $parsed['details']['student_name'], 'Names are trimmed.' );
taas_input_check( 'US' === $parsed['details']['profile']['country_code'], 'Country codes are normalized.' );
taas_input_check( null === $parsed['details']['profile']['resume_url'] && null === $parsed['details']['profile']['english_test'], 'Missing optional values become null.' );
taas_input_check( '' === $parsed['details']['profile']['additional_info'] && '' === $parsed['details']['academic_background'][0]['minor'], 'Missing optional text becomes empty text.' );
echo "PASS: Normalized output and optional defaults\n";

// Exercise the same strict integer rule at each nesting level.
$integer_paths = array(
	array( 'term', 'semester' ),
	array( 'term', 'year' ),
	array( 'details', 'profile', 'available_hours' ),
	array( 'details', 'profile', 'enrollment_status' ),
	array( 'details', 'profile', 'eap5836_status' ),
	array( 'details', 'profile', 'eap5837_status' ),
	array( 'details', 'academic_background', 0, 'degree_type' ),
	array( 'course_preferences', 0, 'rank' ),
);
foreach ( $integer_paths as $path ) {
	foreach ( array( '1', true, false, null, 1.0, array() ) as $invalid ) {
		$request = $body;
		$target =& $request;
		foreach ( $path as $key ) {
			$target =& $target[ $key ]; }
		$target = $invalid;
		unset( $target );
		taas_input_rejects( $request, 'An integer field accepted a different data type.' );
	}
}
echo "PASS: Strict integer types throughout the request\n";

foreach ( array( 0, 4, null, 3.456 ) as $gpa ) {
	$request = $body;
	$request['details']['academic_background'][0]['gpa'] = $gpa;
	$actual = TAAS_Student_Application_Input::parse( $request )['details']['academic_background'][0]['gpa'];
	taas_input_check( ( null === $gpa ? null : round( (float) $gpa, 2 ) ) === $actual, 'GPA normalization changed.' );
}
foreach ( array( -0.01, 4.01, '3.5', true, INF, -INF, NAN ) as $gpa ) {
	$request = $body;
	$request['details']['academic_background'][0]['gpa'] = $gpa;
	taas_input_rejects( $request, 'An invalid GPA was accepted.' );
}
$request = $body;
unset( $request['details']['academic_background'][0]['gpa'] );
taas_input_rejects( $request, 'Missing GPA must not silently mean unavailable.' );
echo "PASS: GPA boundaries, rounding and explicit unavailability\n";

foreach ( array(
	'TOEFL' => array( 0, 5.5, 6.0, 999.9 ),
	'SPEAK' => array( 0, 42, 2147483647 ),
) as $type => $scores ) {
	foreach ( $scores as $score ) {
		$request = $body;
		$request['details']['profile']['english_test'] = array(
			'type'  => $type,
			'score' => $score,
		);
		$test = TAAS_Student_Application_Input::parse( $request )['details']['profile']['english_test'];
		taas_input_check( $type === $test['type'] && ( 'TOEFL' === $type ? (float) $score : (int) $score ) === $test['score'], 'English score must be stored without rounding.' );
	}
}
foreach ( array(
	'TOEFL' => array( -1, 1000, 6.49, '6', true, null, INF, NAN ),
	'SPEAK' => array( -1, 2147483648, 42.5, '42', true, null, INF, NAN ),
) as $type => $scores ) {
	foreach ( $scores as $score ) {
		$request = $body;
		$request['details']['profile']['english_test'] = array(
			'type'  => $type,
			'score' => $score,
		);
		taas_input_rejects( $request, 'An invalid English score was accepted.' );
	}
}
echo "PASS: English score types, decimal precision and storage boundaries\n";

foreach ( array( '2025-02-29', '2024-02-29', '2025-13-01', '2025-00-01', '2025-05-02', '0999-01-01', '2025-5-01', "2025-05-01\0" ) as $date ) {
	$request = $body;
	$request['details']['academic_background'][0]['grad_date'] = $date;
	taas_input_rejects( $request, 'An invalid graduation month was accepted.' );
}
echo "PASS: Invalid dates and non-first-of-month dates\n";

foreach ( array( 'https://example.com/resume.pdf', 'http://example.com/resume.pdf', '', null ) as $url ) {
	$request = $body;
	$request['details']['profile']['resume_url'] = $url;
	$actual = TAAS_Student_Application_Input::parse( $request )['details']['profile']['resume_url'];
	taas_input_check( ( '' === $url ? null : $url ) === $actual, 'Resume URL normalization changed.' );
}
foreach ( array( 'javascript:alert(1)', 'data:text/html,test', 'file:///etc/passwd', 'ftp://example.com/file', '//example.com/file', 'google.com' ) as $url ) {
	$request = $body;
	$request['details']['profile']['resume_url'] = $url;
	taas_input_rejects( $request, 'An unsafe or incomplete resume URL was accepted.' );
}
echo "PASS: Resume URLs allow only complete HTTP/HTTPS URLs\n";

foreach ( array( 'term', 'details', 'course_preferences' ) as $key ) {
	$request = $body;
	unset( $request[ $key ] );
	taas_input_rejects( $request, 'A missing required section was accepted.' );
	foreach ( array( null, true, 'value', array( 'unexpected' ) ) as $invalid ) {
		$request = $body;
		$request[ $key ] = $invalid;
		taas_input_rejects( $request, 'A malformed section was accepted.' );
	}
}
foreach ( array( 'profile', 'academic_background' ) as $key ) {
	$request = $body;
	unset( $request['details'][ $key ] );
	taas_input_rejects( $request, 'A missing nested section was accepted.' );
}
$request = $body;
$request['details']['profile']['student_ufid'] = '12345678';
taas_input_rejects( $request, 'An injected owner field was accepted.' );
$request = $body;
$request['course_preferences'][0]['application_status'] = 2;
taas_input_rejects( $request, 'An injected matching outcome was accepted.' );
echo "PASS: Required structure and protected field allowlists\n";

$request = $body;
$request['course_preferences'] = array();
taas_input_rejects( $request, 'Creation accepted no courses.' );
taas_input_check( array() === TAAS_Student_Application_Input::parse( $request, true )['course_preferences'], 'Editing must allow withdrawal of all courses.' );
foreach ( array( false, 'true', 1, null ) as $completed ) {
	$request = $body;
	$request['course_preferences'][0]['has_completed_course'] = $completed;
	taas_input_rejects( $request, 'Completion must be explicitly true.' );
}
$request = $body;
$request['course_preferences'][] = array(
	'course_id'            => '102',
	'rank'                 => 2,
	'has_completed_course' => true,
);
$request['course_preferences'] = array_reverse( $request['course_preferences'] );
taas_input_check( array( 1, 2 ) === array_column( TAAS_Student_Application_Input::parse( $request )['course_preferences'], 'rank' ), 'Course output must be sorted by rank.' );
$request['course_preferences'][0]['rank'] = 1;
taas_input_rejects( $request, 'Duplicate ranks were accepted.' );
$request['course_preferences'][0]['rank'] = 2;
$request['course_preferences'][0]['course_id'] = '101';
taas_input_rejects( $request, 'Duplicate courses were accepted.' );
echo "PASS: Course completion, uniqueness, ordering and withdrawal\n";

foreach ( array( 'course-A', '0', '-1', '+1', '01', '1.5', '1e2', '9223372036854775808', 101, true, null ) as $course_id ) {
	$request = $body;
	$request['course_preferences'][0]['course_id'] = $course_id;
	taas_input_rejects( $request, 'Course IDs must be canonical positive numeric strings within PHP integer range.' );
}
echo "PASS: Invalid or ambiguous numeric course IDs are rejected\n";
