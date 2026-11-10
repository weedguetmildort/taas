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
			'phone'              => '3525550100',
			'country_code'       => 'us',
			'admitted_semester'  => 'Fall 2025',
			'available_hours'    => 10,
			'enrollment_status'  => 2,
			'english_test_score' => 2,
			'eap5836_status'     => 1,
			'eap5837_status'     => 4,
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
taas_input_check( null === $parsed['details']['profile']['resume_url'], 'Missing optional values become null.' );
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

foreach ( array( 1, 2, 3 ) as $program ) {
	foreach ( array( 1, 2, 3, 4 ) as $category ) {
		$request = $body;
		$request['details']['profile']['enrollment_status'] = $program;
		$request['details']['profile']['fws_eligibility'] = 'no';
		$request['details']['profile']['english_test_score'] = $category;
		taas_input_check( $category === TAAS_Student_Application_Input::parse( $request )['details']['profile']['english_test_score'], 'Every program accepts each category, including N/A.' );
	}
	foreach ( array( null, '', 0, 5, -1, 1.5, '2', true, INF, NAN ) as $invalid_score ) {
		$request['details']['profile']['english_test_score'] = $invalid_score;
		taas_input_rejects( $request, 'Invalid category accepted.' );
	}
	unset( $request['details']['profile']['english_test_score'] );
	taas_input_rejects( $request, 'An unanswered category must not mean N/A.' );
}
foreach ( array( 'toefl_ibt_score', 'speak_score', 'english_test', 'toefl_ibt_date', 'speak_date' ) as $retired ) {
	$request = $body;
	$request['details']['profile'][ $retired ] = null;
	taas_input_rejects( $request, 'Retired exam fields must not be accepted.' );
}
echo "PASS: Required English score category accepts 1–4 for all programs and rejects retired fields\n";

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
foreach ( array( 'true', 1, null ) as $completed ) {
	$request = $body;
	$request['course_preferences'][0]['has_completed_course'] = $completed;
	taas_input_rejects( $request, 'Completion must be an explicit boolean.' );
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

foreach ( array( true, false ) as $experience ) {
	$request = $body;
	$request['course_preferences'][0]['has_ta_for_course'] = $experience;
	taas_input_check( $experience === TAAS_Student_Application_Input::parse( $request )['course_preferences'][0]['has_ta_for_course'], 'TA declaration preserves its boolean value.' );
}
taas_input_check( false === TAAS_Student_Application_Input::parse( $body )['course_preferences'][0]['has_ta_for_course'], 'Omitted TA declaration defaults to false.' );
foreach ( array( 'true', 'false', 1, 0, null, array() ) as $experience ) {
	$request = $body;
	$request['course_preferences'][0]['has_ta_for_course'] = $experience;
	taas_input_rejects( $request, 'TA declaration must be a JSON boolean.' );
}
echo "PASS: Previous TA experience accepts only booleans\n";

foreach ( array( 'course-A', '0', '-1', '+1', '01', '1.5', '1e2', '9223372036854775808', 101, true, null ) as $course_id ) {
	$request = $body;
	$request['course_preferences'][0]['course_id'] = $course_id;
	taas_input_rejects( $request, 'Course IDs must be canonical positive numeric strings within PHP integer range.' );
}
echo "PASS: Invalid or ambiguous numeric course IDs are rejected\n";

$request = $body;
$request['details']['profile']['enrollment_status'] = 1;
taas_input_rejects( $request, 'Undergraduates must answer FWS eligibility.' );
foreach ( array( null, '', true, false, 1, 'N/A', 'unknown', array() ) as $invalid ) {
	$request['details']['profile']['fws_eligibility'] = $invalid;
	taas_input_rejects( $request, 'FWS eligibility accepted an invalid answer.' );
}
foreach ( array( 'no', 'not_applicable' ) as $answer ) {
	$request['details']['profile']['fws_eligibility'] = $answer;
	$profile = TAAS_Student_Application_Input::parse( $request )['details']['profile'];
	taas_input_check( $answer === $profile['fws_eligibility'] && null === $profile['fws_award_letter_url'], 'No and N/A do not require an award letter.' );
}
$request['details']['profile']['fws_eligibility'] = 'yes';
taas_input_rejects( $request, 'Yes requires an FWS award letter URL.' );
foreach ( array( null, '', '  ', true, array(), 'javascript:alert(1)', 'ftp://example.com/letter', '//example.com/letter', 'example.com', 'https://example.com/' . str_repeat( 'a', 255 ) ) as $url ) {
	$request['details']['profile']['fws_award_letter_url'] = $url;
	taas_input_rejects( $request, 'An invalid FWS award letter URL was accepted.' );
}
$request['details']['profile']['resume_url'] = 'https://example.com/resume';
foreach ( array( 'https://example.com/letter', 'http://example.com/letter' ) as $url ) {
	$request['details']['profile']['fws_award_letter_url'] = ' ' . $url . ' ';
	$profile = TAAS_Student_Application_Input::parse( $request )['details']['profile'];
	taas_input_check( $url === $profile['fws_award_letter_url'] && 'https://example.com/resume' === $profile['resume_url'], 'FWS award letters are trimmed and remain separate from resumes.' );
}
foreach ( array( 2, 3 ) as $graduate_status ) {
	$request['details']['profile']['enrollment_status'] = $graduate_status;
	$profile = TAAS_Student_Application_Input::parse( $request )['details']['profile'];
	taas_input_check( 'no' === $profile['fws_eligibility'] && null === $profile['fws_award_letter_url'], 'Graduate saves discard hidden FWS data on the server.' );
}
echo "PASS: Required FWS answers, conditional award letter validation, and graduate clearing\n";
