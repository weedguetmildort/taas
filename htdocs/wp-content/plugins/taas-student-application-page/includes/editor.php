<?php
/** WordPress-editable student form blocks; backend field identifiers stay stable. */
defined( 'ABSPATH' ) || exit;

add_action( 'init', 'taas_student_register_editor_blocks' );
add_filter( 'block_categories_all', static function ( array $categories ): array {
	$categories[] = array( 'slug' => 'taas-student', 'title' => 'TAAS Student Pages' );
	return $categories;
} );

/** Allowed input types/options mirror the existing form and REST contract. */
function taas_student_editor_fields(): array {
	$eap = array( '' => 'Select status', 'uf_taken' => 'Taken at UF', 'currently_taking' => 'Currently taking', 'not_taken' => 'Not taken', 'other_university' => 'Taken at another university' );
	$english = array( '' => 'Not applicable' );
	for ( $score = 20; $score <= 60; $score += 5 ) {
		$english[ 'SPEAK-' . $score ] = 'SPEAK-' . $score;
	}
	for ( $score = 6; $score >= 1; $score -= .5 ) {
		$key = 'TOEFL-' . number_format( $score, 1 );
		$english[ $key ] = $key;
	}
	return array(
		'firstName' => array( 'label' => 'First name', 'type' => 'text', 'autocomplete' => 'given-name', 'maxlength' => 255 ),
		'lastName' => array( 'label' => 'Last name', 'type' => 'text', 'autocomplete' => 'family-name', 'maxlength' => 255 ),
		'studentName' => array( 'label' => 'Full name', 'type' => 'text', 'autocomplete' => 'name', 'maxlength' => 255 ),
		'ufid' => array( 'label' => 'UFID', 'type' => 'text', 'readonly' => true, 'maxlength' => 8, 'pattern' => '[0-9]{8}', 'help' => 'Your verified UFID is supplied by your account.' ),
		'ufEmail' => array( 'label' => 'UF email', 'type' => 'email', 'maxlength' => 255, 'pattern' => '.+@[uU][fF][lL][.][eE][dD][uU]' ),
		'countryOfOrigin' => array( 'label' => 'Country of origin', 'type' => 'text', 'list' => 'country-options', 'autocomplete' => 'country-name' ),
		'startSemester' => array( 'label' => 'Semester you started at UF', 'type' => 'select', 'id' => 'start-semester', 'options' => array( '' => 'Select semester' ) ),
		'collegeStatus' => array( 'label' => 'Current college status', 'type' => 'select', 'options' => array( '' => 'Select status', 'masters' => "Master's", 'phd' => 'PhD' ) ),
		'phone' => array( 'label' => 'Phone number', 'type' => 'tel', 'autocomplete' => 'tel', 'maxlength' => 15 ),
		'eap5836Status' => array( 'label' => 'EAP5836 status', 'type' => 'select', 'options' => $eap ),
		'eap5837Status' => array( 'label' => 'EAP5837 status', 'type' => 'select', 'options' => $eap ),
		'taHours' => array( 'label' => 'Planned TA hours per week', 'type' => 'select', 'options' => array( '' => 'Select hours', '10' => '10 hours', '20' => '20 hours' ) ),
		'englishScore' => array( 'label' => 'English score (if applicable)', 'type' => 'select', 'optional' => true, 'options' => $english ),
		'additionalInfo' => array( 'label' => 'Anything else the professor should know, including planned travel', 'type' => 'textarea', 'optional' => true, 'maxlength' => 1000 ),
		'resumeUrl' => array( 'label' => 'Resume URL (optional)', 'type' => 'url', 'optional' => true, 'maxlength' => 255, 'placeholder' => 'https://…' ),
	);
}

/** Definitions are shared with the editor, avoiding divergent attribute defaults. */
function taas_student_editor_block_definitions(): array {
	return array(
		'homepage-link' => array( 'title' => 'Student homepage link', 'navigation' => true, 'copy' => array( 'label' => '', 'alreadyAppliedMessage' => 'You have already applied for {term}.', 'unavailableMessage' => 'Application information is unavailable. You can still open the application page.' ) ),
		'application-form' => array( 'title' => 'Application form', 'container' => true, 'copy' => array( 'submitLabel' => 'Submit Application', 'savedLabel' => 'Application submitted', 'loadingText' => 'Loading your application…', 'retryLabel' => 'Retry loading' ) ),
		'application-field' => array( 'title' => 'Application field', 'copy' => array( 'label' => '', 'help' => '', 'placeholder' => '' ), 'field' => true ),
		'application-term' => array( 'title' => 'Application term', 'copy' => array( 'prefix' => 'Applying for:', 'loadingText' => 'Loading application information…' ) ),
		'application-notice' => array( 'title' => 'Already-applied notice', 'copy' => array( 'message' => 'You have already applied for this term. You can view your course preferences on', 'statusLabel' => 'Check Application Status', 'help' => 'If you believe this is an error, you can start a new form. Your existing application will be preserved.', 'recoverLabel' => 'Start a new form' ) ),
		'academic-background' => array( 'title' => 'Academic background controls', 'copy' => array( 'entryLabel' => 'Degree', 'addLabel' => 'Add another degree', 'removeLabel' => 'Remove degree', 'universityLabel' => 'University', 'majorLabel' => 'Major', 'degreeLabel' => 'Degree type', 'graduationLabel' => 'Graduation date (MM/YYYY)', 'gpaLabel' => 'GPA (out of 4.0)', 'unavailableLabel' => 'GPA not yet available' ) ),
		'course-preferences' => array( 'title' => 'Course preference controls', 'copy' => array( 'chooserTitle' => 'Available courses', 'chooserHelp' => 'Check up to five courses.', 'rankingTitle' => 'Your course rankings', 'rankingHelp' => 'Set a different rank for each course and answer the course-history question.', 'rankLabel' => 'Preference rank', 'rankPlaceholder' => 'Select rank', 'completionLabel' => 'Have you completed this course?', 'completionPlaceholder' => 'Select answer', 'completedLabel' => 'Yes, I have completed this course', 'equivalentLabel' => 'I completed an equivalent course at another institution', 'incompleteLabel' => 'No, I have not completed this course', 'courseNumberLabel' => 'Course number', 'courseNameLabel' => 'Course name', 'instructorLabel' => 'Instructor', 'daysLabel' => 'Class days', 'timesLabel' => 'Class times', 'emptyRankings' => 'Select one or more courses to rank them here.', 'readOnlyEmptyRankings' => 'No active course preferences remain for this term.' ) ),
		'application-success' => array( 'title' => 'Submission confirmation', 'copy' => array( 'title' => 'Application submitted', 'message' => "Your form was successfully submitted. You can check your application's status on Check Application Status.", 'statusLabel' => 'Check Application Status', 'homeLabel' => 'Back to homepage' ) ),
		'application-status' => array( 'title' => 'Application status controls', 'copy' => array( 'termLabel' => 'Academic term', 'termPlaceholder' => 'Select academic term', 'loadingText' => 'Loading your applications…', 'retryLabel' => 'Retry loading', 'courseLabel' => 'Course', 'rankLabel' => 'Preference rank', 'statusLabel' => 'Current status', 'editLabel' => 'Edit Application', 'viewLabel' => 'View Application Details', 'reloadLabel' => 'Reload saved application' ) ),
		'application-editor' => array( 'title' => 'Saved application editor', 'container' => true, 'copy' => array( 'editTitle' => 'Edit Application', 'viewTitle' => 'Application Details' ) ),
	);
}

/** Register the editable controls alongside ordinary core Heading/Paragraph/Group blocks. */
function taas_student_register_editor_blocks(): void {
	$definitions = taas_student_editor_block_definitions();
	$editor = 'assets/editor-blocks.js';
	$css = 'assets/student-application.css';
	wp_register_style( 'taas-student-application', TAAS_STUDENT_PAGES_URL . $css, array(), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $css ) );
	$home_css = 'assets/front-page.css';
	wp_register_style( 'taas-student-homepage', TAAS_STUDENT_PAGES_URL . $home_css, array(), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $home_css ) );
	wp_register_script( 'taas-student-editor', TAAS_STUDENT_PAGES_URL . $editor, array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ), (string) filemtime( TAAS_STUDENT_PAGES_PATH . $editor ), true );
	wp_add_inline_script( 'taas-student-editor', 'window.TAASStudentEditor = ' . wp_json_encode( array( 'blocks' => $definitions, 'fields' => taas_student_editor_fields() ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	foreach ( $definitions as $slug => $definition ) {
		$attributes = array();
		foreach ( $definition['copy'] as $key => $default ) {
			$attributes[ $key ] = array( 'type' => 'string', 'default' => $default );
		}
		if ( ! empty( $definition['field'] ) ) {
			$attributes['field'] = array( 'type' => 'string', 'default' => 'phone' );
			$attributes['optionLabels'] = array( 'type' => 'object', 'default' => array() );
		}
		if ( ! empty( $definition['navigation'] ) ) {
			$attributes['destination'] = array( 'type' => 'string', 'enum' => array( 'student-application', 'check-application-status', 'professor-page' ), 'default' => 'student-application' );
		}
		register_block_type(
			'taas/' . $slug,
			array(
				'api_version' => 3, 'attributes' => $attributes, 'editor_script' => 'taas-student-editor',
				'editor_style' => ! empty( $definition['navigation'] ) ? 'taas-student-homepage' : 'taas-student-application', 'supports' => array( 'html' => false ),
				'render_callback' => static function ( array $attrs, string $content ) use ( $slug ): string { return taas_student_render_editor_block( $slug, $attrs, $content ); },
			)
		);
	}
}

/** Render one field while keeping the validated backend name/options independent of its label. */
function taas_student_render_editor_field( array $a ): string {
	$definitions = taas_student_editor_fields();
	$field = $a['field'];
	if ( ! isset( $definitions[ $field ] ) ) {
		return '';
	}
	$d = $definitions[ $field ];
	$label = '' !== $a['label'] ? $a['label'] : $d['label'];
	$help = '' !== $a['help'] ? $a['help'] : ( $d['help'] ?? '' );
	$attributes = ' name="' . esc_attr( $field ) . '"';
	if ( ! empty( $d['readonly'] ) ) {
		$attributes .= ' readonly';
	}
	if ( ! in_array( $d['type'], array( 'select', 'textarea' ), true ) ) {
		$attributes .= ' type="' . esc_attr( $d['type'] ) . '"';
	}
	if ( empty( $d['optional'] ) ) {
		$attributes .= ' required';
	}
	foreach ( array( 'maxlength', 'pattern', 'autocomplete', 'id', 'list' ) as $key ) {
		if ( isset( $d[ $key ] ) ) {
			$attributes .= ' ' . $key . '="' . esc_attr( (string) $d[ $key ] ) . '"';
		}
	}
	$placeholder = '' !== $a['placeholder'] ? $a['placeholder'] : ( $d['placeholder'] ?? '' );
	if ( '' !== $placeholder ) {
		$attributes .= ' placeholder="' . esc_attr( $placeholder ) . '"';
	}
	if ( 'select' === $d['type'] ) {
		$input = '<select' . $attributes . '>';
		foreach ( $d['options'] as $value => $text ) {
			$input .= '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $a['optionLabels'][ $value ] ?? $text ) . '</option>';
		}
		$input .= '</select>';
	} elseif ( 'textarea' === $d['type'] ) {
		$input = '<textarea rows="5"' . $attributes . '></textarea>';
	} else {
		$input = '<input' . $attributes . '>';
	}
	if ( 'countryOfOrigin' === $field ) {
		$input .= '<datalist id="country-options"></datalist>';
	}
	return '<div ' . get_block_wrapper_attributes() . '><label>' . esc_html( $label ) . $input . ( '' !== $help ? '<small>' . esc_html( $help ) . '</small>' : '' ) . '</label></div>';
}

/** Only stable interactive scaffolding is code-owned; copy is stored in each page's blocks. */
function taas_student_render_editor_block( string $slug, array $a, string $content ): string {
	$e = static fn( string $key ): string => esc_html( $a[ $key ] );
	switch ( $slug ) {
		case 'homepage-link':
			return taas_student_homepage_link( $a );
		case 'application-field':
			return taas_student_render_editor_field( $a );
		case 'application-form':
			return '<form id="student-application-form" novalidate ' . get_block_wrapper_attributes() . '><fieldset class="application-fields" id="application-fields" disabled>' . $content . '</fieldset><footer class="form-actions"><p class="student-app-message" id="form-message" role="status" aria-live="polite">' . $e( 'loadingText' ) . '</p><button type="button" id="retry-application-load" hidden>' . $e( 'retryLabel' ) . '</button><button type="submit" data-saved-label="' . esc_attr( $a['savedLabel'] ) . '" disabled>' . $e( 'submitLabel' ) . '</button></footer></form>';
		case 'application-term':
			return '<p class="term-banner" id="application-term" role="status"><strong>' . $e( 'prefix' ) . '</strong> <span data-term-value>' . $e( 'loadingText' ) . '</span></p>';
		case 'application-notice':
			return '<section class="application-notice" id="existing-application-notice" hidden aria-label="Existing application"><p>' . $e( 'message' ) . ' <a id="existing-application-link">' . $e( 'statusLabel' ) . '</a>.</p><p>' . $e( 'help' ) . '</p><button type="button" id="start-recovery-form">' . $e( 'recoverLabel' ) . '</button></section>';
		case 'academic-background':
			$markup = (string) file_get_contents( TAAS_CORE_PATH . 'pages/partials/academic-background-controls.html' );
			$replace = array();
			foreach ( $a as $key => $value ) {
				if ( is_string( $value ) ) {
					$replace[ '{{' . $key . '}}' ] = esc_html( $value );
				}
			}
			return strtr( $markup, $replace );
		case 'course-preferences':
			return '<div class="course-selection-layout" data-course-copy="' . esc_attr( wp_json_encode( $a ) ) . '"><div class="course-chooser"><h3>' . $e( 'chooserTitle' ) . '</h3><p class="course-subhelp">' . $e( 'chooserHelp' ) . '</p><div id="available-course-list" class="available-course-list" aria-describedby="course-selection-message"></div><p id="course-selection-message" class="course-selection-message" role="status" aria-live="polite"></p></div><div class="course-ranking-panel"><h3>' . $e( 'rankingTitle' ) . '</h3><p class="course-subhelp">' . $e( 'rankingHelp' ) . '</p><div id="student-course-preferences" class="preferences-grid"></div></div></div>';
		case 'application-success':
			return '<dialog class="application-dialog" id="application-success" aria-labelledby="application-success-title" aria-describedby="application-success-description"><h2 id="application-success-title">' . $e( 'title' ) . '</h2><p id="application-success-description">' . $e( 'message' ) . '</p><div class="dialog-actions"><a class="action-link" id="success-status-link">' . $e( 'statusLabel' ) . '</a><a class="action-link" id="success-home-link">' . $e( 'homeLabel' ) . '</a></div></dialog>';
		case 'application-editor':
			return '<section id="status-editor" hidden aria-labelledby="status-editor-title"><h3 id="status-editor-title" data-edit-title="' . esc_attr( $a['editTitle'] ) . '" data-view-title="' . esc_attr( $a['viewTitle'] ) . '">' . $e( 'editTitle' ) . '</h3>' . $content . '</section>';
		case 'application-status':
			return '<div ' . get_block_wrapper_attributes() . '><div class="status-controls"><label for="status-term">' . $e( 'termLabel' ) . '<select id="status-term" data-placeholder="' . esc_attr( $a['termPlaceholder'] ) . '" disabled><option value="">' . $e( 'termPlaceholder' ) . '</option></select></label></div><p class="status-message" id="status-message" role="status" aria-live="polite">' . $e( 'loadingText' ) . '</p><button type="button" class="edit-application-button" id="status-retry" hidden>' . $e( 'retryLabel' ) . '</button><div class="application-status-table-wrapper" id="status-table-wrapper" hidden><table class="application-status-table" aria-label="Course preferences for the selected academic term"><thead><tr><th scope="col">' . $e( 'courseLabel' ) . '</th><th scope="col">' . $e( 'rankLabel' ) . '</th><th scope="col">' . $e( 'statusLabel' ) . '</th></tr></thead><tbody id="status-course-rows"></tbody></table></div><div class="status-actions" id="status-actions" hidden><button type="button" class="edit-application-button" id="edit-saved-application" data-edit-label="' . esc_attr( $a['editLabel'] ) . '" data-view-label="' . esc_attr( $a['viewLabel'] ) . '">' . $e( 'editLabel' ) . '</button><button type="button" class="edit-application-button" id="reload-saved-application">' . $e( 'reloadLabel' ) . '</button></div></div>';
	}
	return $content;
}
