<?php
/** Homepage term/ownership checks. Every fixture is rolled back; no browser needed. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) { throw new RuntimeException( 'Local development required.' ); }

function taas_home_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}
$original_get = $_GET;
$_GET['taas_role'] = 'student';
$owner = TAAS_Auth::current_user()['university_id'];
$period = array( 'semester' => 1, 'year' => 2155, 'is_open' => true, 'closes_at' => null );
$filter = static function () use ( &$period ) { return $period; };
add_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $filter );
$submissions = new TAAS_Student_Submission_Repository();
$applications = new TAAS_Application_Repository();
$students = new TAAS_Student_Repository();
$courses = new TAAS_Course_Repository();
$professors = new TAAS_Professor_Repository();
$repos = array( $submissions, $applications, $students, $courses, $professors );
$before = array_map( static fn( $repo ) => $repo->count(), $repos );
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
try {
	while ( $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, $period['year'] ) || $applications->find_by_student_and_term( $owner, TAAS_Semester::Fall, $period['year'] ) ) { --$period['year']; }
	if ( ! $students->find( $owner ) ) { $students->insert( new TAAS_Student( $owner, 'Homepage test', 'home@ufl.edu' ) ); }
	taas_home_check( false === taas_student_homepage_state()['already_applied'], 'Applications in other terms do not mark the configured term as applied' );
	taas_home_check( false === taas_student_homepage_config()['alreadyApplied'], 'New-applicant configuration has no already-applied warning' );
	$other = '99995000';
	while ( $students->find( $other ) ) { $other = (string) ( (int) $other - 1 ); }
	$students->insert( new TAAS_Student( $other, 'Another student', 'other@example.invalid' ) );
	$submissions->save_for_term( $other, TAAS_Semester::Fall, $period['year'], array( 'student_name' => 'Another student' ) );
	$_GET['student_ufid'] = $other;
	taas_home_check( false === taas_student_homepage_state()['already_applied'], 'Another student and a forged URL owner cannot affect homepage state' );
	$submissions->save_for_term( $owner, TAAS_Semester::Fall, $period['year'], array( 'student_name' => 'Homepage test' ) );
	taas_home_check( true === taas_student_homepage_state()['already_applied'], 'A term snapshot counts even with no active course preferences' );
	$config = taas_student_homepage_config();
	taas_home_check( $config['alreadyApplied'] && $config['termLabel'] === 'Fall ' . $period['year'], 'Existing-applicant configuration supplies the correct term' );
	foreach ( array( 'student-application', 'check-application-status', 'professor-page' ) as $destination ) {
		taas_home_check( $config['urls'][ $destination ] === TAAS_Pages::url( $destination ), $destination . ' supplies the managed page URL' );
	}
	$period['is_open'] = false;
	taas_home_check( true === taas_student_homepage_config()['alreadyApplied'], 'Closing applications preserves status feedback and navigation' );
	$period['is_open'] = true;
	// Legacy course rows without a snapshot must match initial-form detection too.
	$submissions->delete( $submissions->find_by_student_and_term( $owner, TAAS_Semester::Fall, $period['year'] )->submission_id );
	$professor = '99994000';
	while ( $professors->find( $professor ) ) { $professor = (string) ( (int) $professor - 1 ); }
	$professors->insert( new TAAS_Professor( $professor, 'Homepage test', 'home-professor@example.invalid' ) );
	$id = 'home-' . bin2hex( random_bytes( 5 ) );
	$courses->insert( new TAAS_Course( $id, 'TEST', 'Homepage test course', $professor, TAAS_Semester::Fall, $period['year'] ) );
	$applications->insert( new TAAS_Application( null, $owner, $id, true, false, null, 1, null, TAAS_Match_Status::Withdrawn ) );
	taas_home_check( true === taas_student_homepage_state()['already_applied'], 'Withdrawn legacy course rows still count as an existing application' );
	$period = array();
	taas_home_check( is_wp_error( taas_student_homepage_state() ) && taas_student_homepage_config()['unavailable'], 'Missing configuration shows an unavailable state, not a false already-applied result' );
	$_GET['taas_role'] = 'instructor';
	taas_home_check( is_wp_error( taas_student_homepage_state() ) && false === taas_student_homepage_config()['alreadyApplied'], 'Non-students receive no personalized homepage controls' );
} finally {
	$wpdb->query( 'ROLLBACK' );
	remove_filter( 'pre_option_' . TAAS_Application_Period::OPTION, $filter );
	$_GET = $original_get;
}
taas_home_check( $before === array_map( static fn( $repo ) => $repo->count(), $repos ), 'All homepage fixtures rolled back' );
