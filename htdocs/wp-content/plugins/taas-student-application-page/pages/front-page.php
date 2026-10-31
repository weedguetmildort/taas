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

/** Render a managed navigation link with editor-owned wording. */
function taas_student_homepage_link( array $attributes ): string {
	// TODO: Core exposes only one role; update this check with its deferred multi-role fix.
	$user = TAAS_Auth::current_user();
	if ( null === $user || TAAS_Auth::ROLE_STUDENT !== $user['role'] ) {
		return '';
	}
	$labels = array(
		'student-application' => 'Apply for TA Position',
		'check-application-status' => 'Check Status of Application',
		'professor-page' => 'PhD teaching an Undergrad class? Click here for Professor View',
	);
	$destination = $attributes['destination'] ?? 'student-application';
	if ( ! isset( $labels[ $destination ] ) ) {
		return '';
	}
	$url = TAAS_Pages::url( $destination );
	if ( '' === $url ) {
		return '';
	}
	$label = '' !== ( $attributes['label'] ?? '' ) ? $attributes['label'] : $labels[ $destination ];
	$professor = 'professor-page' === $destination;
	$message = '';
	if ( 'student-application' === $destination ) {
		$state = taas_student_homepage_state();
		if ( is_wp_error( $state ) ) {
			$message = $attributes['unavailableMessage'];
		} elseif ( $state['already_applied'] ) {
			$message = str_replace( '{term}', $state['period']['label'], $attributes['alreadyAppliedMessage'] );
		}
	}
	$id = wp_unique_id( 'student-home-application-note-' );
	$description = '' !== $message ? ' aria-describedby="' . esc_attr( $id ) . '"' : '';
	return '<div ' . get_block_wrapper_attributes( array( 'class' => 'student-home-link' ) ) . '><a class="' . ( $professor ? 'student-professor-button' : 'student-home-button' ) . '" href="' . esc_url( $url ) . '"' . $description . '>'
		. esc_html( $label ) . ( $professor ? '' : '<span class="home-arrow" aria-hidden="true">→</span>' ) . '</a>'
		. ( '' !== $message ? '<p class="student-home-application-note" id="' . esc_attr( $id ) . '">' . esc_html( $message ) . '</p>' : '' ) . '</div>';
}
