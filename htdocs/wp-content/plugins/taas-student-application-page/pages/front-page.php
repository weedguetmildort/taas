<?php
/** Student homepage setup and authenticated, term-specific application state. */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'taas_student_homepage_assets' );

/** Load the homepage styling independently of either application form. */
function taas_student_homepage_assets(): void {
	if ( ! taas_student_pages_is_page( 'front-page' ) ) {
		return;
	}
	$file = 'assets/front-page.css';
	wp_enqueue_style( 'taas-student-homepage', TAAS_STUDENT_PAGES_URL . $file, array(), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $file ) );
	// TODO: Adapt this check with core's deferred multi-role fix.
	$user = TAAS_Auth::current_user();
	if ( null === $user || TAAS_Auth::ROLE_STUDENT !== $user['role'] ) { return; }
	$script = 'assets/front-page.js';
	wp_enqueue_script( 'taas-student-homepage', TAAS_STUDENT_PAGES_URL . $script, array(), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $script ), true );
	wp_add_inline_script( 'taas-student-homepage', 'window.TAASStudentHome = ' . wp_json_encode( taas_student_homepage_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
}

/**
 * Read only what homepage navigation needs; no full profile/course-list request.
 *
 * Ownership always comes from the authenticated account. A snapshot still counts
 * after all preferences are withdrawn, matching initial-submission detection.
 *
 * @return array|WP_Error
 */
function taas_student_homepage_state() {
	// TODO: Core resolves one priority role; use all assigned roles after its deferred fix.
	// TODO: Verify upstream session/UFID mapping; do not accept a URL-supplied owner.
	$user = TAAS_Auth::current_user();
	if ( null === $user || TAAS_Auth::ROLE_STUDENT !== $user['role']
		|| ! preg_match( '/^[0-9]{8}$/', (string) ( $user['university_id'] ?? '' ) ) ) {
		return new WP_Error( 'taas_student_identity_unavailable', 'Your verified student identifier is unavailable. Please contact the administrator.' );
	}
	$period = TAAS_Application_Period::current();
	if ( is_wp_error( $period ) ) {
		return $period;
	}
	try {
		$ufid = (string) $user['university_id'];
		$semester = TAAS_Semester::from( $period['semester'] );
		$submission = ( new TAAS_Student_Submission_Repository() )->find_by_student_and_term( $ufid, $semester, $period['year'] );
		$already_applied = null !== $submission;
		if ( ! $already_applied ) {
			$already_applied = ! empty( ( new TAAS_Application_Repository() )->find_by_student_and_term( $ufid, $semester, $period['year'] ) );
		}
		return array( 'period' => $period, 'already_applied' => $already_applied );
	} catch ( Throwable $error ) {
		return new WP_Error( 'taas_homepage_state_unavailable', 'Application information is unavailable. Please try again later.' );
	}
}

/** Supply data and managed URLs; homepage markup belongs to the HTML page. */
function taas_student_homepage_config(): array {
	$state = taas_student_homepage_state();
	return array(
		'urls' => array(
			'student-application' => TAAS_Pages::url( 'student-application' ),
			'check-application-status' => TAAS_Pages::url( 'check-application-status' ),
			'professor-page' => TAAS_Pages::url( 'professor-page' ),
		),
		'unavailable' => is_wp_error( $state ),
		'alreadyApplied' => ! is_wp_error( $state ) && $state['already_applied'],
		'termLabel' => is_wp_error( $state ) ? '' : $state['period']['label'],
	);
}
