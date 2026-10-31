<?php
/** Shared form assets; page content is editable in WordPress. */
defined( 'ABSPATH' ) || exit;

// Compatibility for existing installations still using the original form shortcode.
add_shortcode( 'taas_student_application_form', 'taas_student_application_legacy_form' );

/** Legacy content remains functional alongside raw HTML and editable TAAS blocks. */
function taas_student_application_legacy_form( $attributes = array() ): string {
	// TODO: Core resolves only one role; check all assigned roles after its deferred fix.
	$user = TAAS_Auth::current_user();
	if ( null === $user || TAAS_Auth::ROLE_STUDENT !== $user['role'] ) {
		return '';
	}
	$edit = 'edit' === ( $attributes['mode'] ?? '' );
	$dir = TAAS_CORE_PATH . 'pages/partials/';
	return strtr( (string) file_get_contents( $dir . 'student-application-form.html' ), array(
		'{{mode}}' => $edit ? 'edit' : 'create',
		'{{name_fields}}' => $edit
			? '<label class="full-width">Full name <input name="studentName" autocomplete="name" maxlength="255" required></label>'
			: '<label>First name <input name="firstName" autocomplete="given-name" maxlength="255" required></label><label>Last name <input name="lastName" autocomplete="family-name" maxlength="255" required></label>',
		'{{submit_label}}' => $edit ? 'Submit Changes' : 'Submit Application',
		'{{existing_notice}}' => $edit ? '' : (string) file_get_contents( $dir . 'student-application-notice.html' ),
		'{{success_dialog}}' => $edit ? '' : (string) file_get_contents( $dir . 'student-application-success.html' ),
	) );
}

/** Enqueue the shared form engine and request configuration on the relevant page. */
function taas_student_application_enqueue_form( array $config ): void {
	$css = 'assets/student-application.css';
	$js  = 'assets/student-application.js';
	wp_enqueue_style( 'taas-student-application', TAAS_STUDENT_PAGES_URL . $css, array(), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $css ) );
	$components = array(
		'taas-application-api' => array( 'application-api.js', array() ),
		'taas-academic-background' => array( 'academic-background.js', array() ),
		'taas-course-preferences' => array( 'course-preferences.js', array() ),
		'taas-application-form' => array( 'application-form.js', array( 'taas-academic-background', 'taas-course-preferences' ) ),
	);
	foreach ( $components as $handle => [ $filename, $dependencies ] ) {
		$file = 'assets/' . $filename;
		wp_enqueue_script( $handle, TAAS_STUDENT_PAGES_URL . $file, $dependencies, (string) filemtime( TAAS_STUDENT_PAGES_PATH . $file ), true );
	}
	wp_enqueue_script( 'taas-student-application', TAAS_STUDENT_PAGES_URL . $js, array( 'taas-application-api', 'taas-application-form' ), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $js ), true );
	wp_add_inline_script( 'taas-student-application', 'window.TAASStudentApplication = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
}
