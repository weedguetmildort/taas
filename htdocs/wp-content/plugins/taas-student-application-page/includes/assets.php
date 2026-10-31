<?php
/** Shared student form styles, scripts, and browser configuration. */
defined( 'ABSPATH' ) || exit;

/**
 * Enqueue shared components and the selected page coordinator.
 *
 * Configuration is exposed to browser code as window.TAASStudentApplication.
 * Create mode uses endpoint for loading/submitting and statusUrl/homeUrl for links.
 * Edit mode needs only the nonce here; the status controller supplies each term's
 * endpoint when opening a saved application. Omitting mode selects create mode.
 *
 * @param array{
 *     nonce: string,
 *     mode?: 'create'|'edit',
 *     endpoint?: string,
 *     statusUrl?: string,
 *     homeUrl?: string
 * } $config Authenticated configuration supplied by the page's asset hook.
 */
function taas_student_enqueue_assets( array $config ): void {
	$css = 'assets/student-application.css';
	$is_edit = 'edit' === ( $config['mode'] ?? 'create' );
	$js = $is_edit ? 'assets/student-application-edit.js' : 'assets/student-application.js';
	$coordinator = $is_edit ? 'taas-student-application-edit' : 'taas-student-application';
	wp_enqueue_style( 'taas-student-application', TAAS_STUDENT_PAGES_URL . $css, array(), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $css ) );
	$components = array(
		'taas-application-api' => array( 'application-api-client.js', array() ),
		'taas-academic-background' => array( 'academic-background.js', array() ),
		'taas-course-preferences' => array( 'course-preferences.js', array() ),
		'taas-application-fields' => array( 'application-fields.js', array() ),
		'taas-application-form' => array( 'application-form.js', array( 'taas-application-fields', 'taas-academic-background', 'taas-course-preferences' ) ),
	);
	foreach ( $components as $handle => [ $filename, $dependencies ] ) {
		$file = 'assets/' . $filename;
		wp_enqueue_script( $handle, TAAS_STUDENT_PAGES_URL . $file, $dependencies, (string) filemtime( TAAS_STUDENT_PAGES_PATH . $file ), true );
	}
	wp_enqueue_script( $coordinator, TAAS_STUDENT_PAGES_URL . $js, array( 'taas-application-api', 'taas-application-form' ), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $js ), true );
	wp_add_inline_script( $coordinator, 'window.TAASStudentApplication = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
}
