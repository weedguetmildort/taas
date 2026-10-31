<?php
/**
 * Local HTTP checks for the student page plugin and published page markup.
 *
 * Run: docker compose exec -T wordpress php /var/www/taas/tests/integration/student-pages.php
 * No application records or settings are changed.
 *
 * @package TAAS_Student_Pages
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require '/var/www/html/wp-load.php';
if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development mode required.' );
}

/** Fail a check clearly. */
function taas_page_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

/** Access the local Apache container with the configured site's Host header. */
function taas_page_get( string $path, array $headers = array() ) {
	$site = wp_parse_url( home_url() );
	$host = $site['host'] . ( isset( $site['port'] ) ? ':' . $site['port'] : '' );
	if ( str_contains( $path, '://' ) ) {
		$url = wp_parse_url( $path );
		$path = ( $url['path'] ?? '/' ) . ( isset( $url['query'] ) ? '?' . $url['query'] : '' );
	}
	$response = wp_remote_get( 'http://127.0.0.1' . $path, array( 'headers' => array( 'Host' => $host ) + $headers, 'redirection' => 0 ) );
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( $response->get_error_message() );
	}
	return $response;
}

$student = taas_page_get( add_query_arg( 'taas_role', 'student', TAAS_Pages::url( 'student-application' ) ) );
taas_page_check( 200 === wp_remote_retrieve_response_code( $student ), 'Student can load application page over HTTP' );
$html = wp_remote_retrieve_body( $student );
taas_page_check( str_contains( $html, 'TAASStudentApplication' ) && str_contains( $html, 'assets/student-application.js' ), 'Student plugin emits API configuration and page-specific script' );
taas_page_check( ! str_contains( $html, 'assets/student-application-edit.js' ), 'Initial application page does not load the edit coordinator' );
taas_page_check( ! str_contains( $html, 'sample_courses' ) && ! str_contains( $html, '/your-plugin/' ), 'Published page contains no sample courses or placeholder endpoint' );
taas_page_check( str_contains( $html, 'name="ufid" readonly' ), 'Verified UFID control is read-only' );
taas_page_check( str_contains( $html, 'name="resumeUrl" type="url"' ) && ! str_contains( $html, 'type="file"' ), 'Resume URL replaces file upload' );
taas_page_check( str_contains( $html, 'degree-entry-template' ) && str_contains( $html, 'gpa_unavailable' ) && str_contains( $html, '<dialog' ), 'Repeated-degree, optional-GPA and success-dialog markup is published' );
preg_match( '/window\.TAASStudentApplication = (\{[^\n]+\});/', $html, $match );
$config = json_decode( $match[1] ?? '', true );
taas_page_check( is_array( $config ) && ! empty( $config['nonce'] ), 'REST nonce is supplied by PHP' );
taas_page_check( str_contains( rawurldecode( $config['endpoint'] ), '/taas/v1/student/application' ), 'Form targets the real student API' );
$api = taas_page_get( $config['endpoint'], array( 'X-WP-Nonce' => $config['nonce'] ) );
$expected_status = is_wp_error( TAAS_Application_Period::current() ) ? 503 : 200;
taas_page_check( $expected_status === wp_remote_retrieve_response_code( $api ) && is_array( json_decode( wp_remote_retrieve_body( $api ), true ) ), 'Page-issued nonce reaches the real JSON API over HTTP' );

$status_page = taas_page_get( add_query_arg( 'taas_role', 'student', TAAS_Pages::url( 'check-application-status' ) ) );
taas_page_check( 200 === wp_remote_retrieve_response_code( $status_page ), 'Student can load status page over HTTP' );
$status_html = wp_remote_retrieve_body( $status_page );
taas_page_check( str_contains( $status_html, 'assets/student-application-edit.js' ) && ! str_contains( $status_html, 'assets/student-application.js' ), 'Status page loads only the edit coordinator' );
taas_page_check( strpos( $status_html, 'assets/student-application-edit.js' ) < strpos( $status_html, 'assets/check-application-status.js' ), 'Edit coordinator loads before the status controller' );
taas_page_check( str_contains( $status_html, 'assets/check-application-status.js' ) && str_contains( $status_html, 'TAASStudentStatus' ), 'Status page loads its own controller and endpoint configuration' );
taas_page_check( str_contains( $status_html, 'id="status-term"' ) && str_contains( $status_html, 'id="status-course-rows"' ), 'Approved term selector and course-status table are published' );
taas_page_check( str_contains( $status_html, 'name="studentName"' ) && str_contains( $status_html, 'Submit Changes' ) && ! str_contains( $status_html, '{{' ), 'Saved-details editor uses the shared rendered form without guessing name parts' );
taas_page_check( substr_count( $status_html, 'id="student-application-form"' ) === 1 && substr_count( $status_html, 'id="application-term"' ) === 1, 'Status page has a single editor with unique form IDs' );
taas_page_check( ! str_contains( $status_html, '/student-profile/' ) && ! str_contains( $status_html, 'Placeholder Application' ), 'Old fake statuses and edit redirects are removed' );
preg_match( '/window\.TAASStudentStatus = (\{[^\n]+\});/', $status_html, $status_match );
$status_config = json_decode( $status_match[1] ?? '', true );
taas_page_check( is_array( $status_config ) && ! empty( $status_config['nonce'] ), 'Status page receives its REST nonce' );
$term_list = taas_page_get( $status_config['listEndpoint'], array( 'X-WP-Nonce' => $status_config['nonce'] ) );
$term_data = json_decode( wp_remote_retrieve_body( $term_list ), true );
taas_page_check( 200 === wp_remote_retrieve_response_code( $term_list ) && isset( $term_data['terms'] ), 'Status page term-list endpoint works with plain or pretty permalinks' );
if ( $term_data['terms'] ) {
	$term = $term_data['terms'][0];
	$term_endpoint = str_replace( array( '__semester__', '__year__' ), array( (string) $term['semester'], (string) $term['year'] ), $status_config['termEndpoint'] );
	$saved_term = taas_page_get( $term_endpoint, array( 'X-WP-Nonce' => $status_config['nonce'] ) );
	$saved_data = json_decode( wp_remote_retrieve_body( $saved_term ), true );
	taas_page_check( 200 === wp_remote_retrieve_response_code( $saved_term ) && isset( $saved_data['revision'], $saved_data['details'], $saved_data['course_preferences'] ), 'Status page can retrieve the real submitted term and its edit token' );
}

foreach ( array( 'student-application', 'check-application-status', 'front-page' ) as $slug ) {
	$url = TAAS_Pages::url( $slug );
	$denied = taas_page_get( add_query_arg( 'taas_role', 'instructor', $url ) );
	$body = wp_remote_retrieve_body( $denied );
	taas_page_check( 403 === wp_remote_retrieve_response_code( $denied ) && str_contains( $body, 'not authorized' ), $slug . ' denies non-students' );
	taas_page_check( str_contains( $body, 'Go to your homepage' ) && ! str_contains( $body, 'TAASStudentApplication' ), $slug . ' provides the role homepage link without a form nonce' );
}

$professor = taas_page_get( add_query_arg( 'taas_role', 'instructor', TAAS_Pages::url( 'professor-homepage' ) ) );
taas_page_check( ! str_contains( wp_remote_retrieve_body( $professor ), 'assets/student-application.js' ), 'Student application script is not loaded on professor pages' );

$home = taas_page_get( add_query_arg( 'taas_role', 'student', TAAS_Pages::url( 'front-page' ) ) );
taas_page_check( 200 === wp_remote_retrieve_response_code( $home ), 'Student homepage loads over HTTP' );
$home_html = wp_remote_retrieve_body( $home );
taas_page_check( str_contains( $home_html, 'assets/front-page.css' ) && ! str_contains( $home_html, 'assets/student-application.js' ), 'Homepage loads only its own student-page assets' );
preg_match( '/window\.TAASStudentHome = (\{[^\n]+\});/', $home_html, $home_match );
$home_config = json_decode( $home_match[1] ?? '', true );
taas_page_check( is_array( $home_config ) && str_contains( $home_html, 'assets/front-page.js' ), 'Homepage supplies data to its HTML navigation adapter' );
foreach ( array( 'student-application', 'check-application-status', 'professor-page' ) as $destination ) {
	taas_page_check( $home_config['urls'][ $destination ] === TAAS_Pages::url( $destination ) && str_contains( $home_html, 'data-student-destination="' . $destination . '"' ), 'Homepage wires the managed ' . $destination . ' destination' );
}
$home_state = taas_student_homepage_state();
if ( ! is_wp_error( $home_state ) && $home_state['already_applied'] ) {
	taas_page_check( $home_config['alreadyApplied'] && $home_config['termLabel'] === $home_state['period']['label'], 'Homepage configuration supplies authenticated term feedback' );
}
$home_content = get_post_field( 'post_content', TAAS_Pages::id( 'front-page' ) );
taas_page_check( str_starts_with( $home_content, '<!-- wp:html -->' ) && ! str_contains( $home_content, '<!-- wp:taas/' ), 'Homepage HTML is editable in the modern editor without custom blocks' );

// Frontend file edits must not silently overwrite saved WordPress content.
$source_path = TAAS_CORE_PATH . 'pages/student-application.html';
$original_source = file_get_contents( $source_path );
$marker = 'taas-source-' . bin2hex( random_bytes( 6 ) );
try {
	file_put_contents( $source_path, $original_source . '<span hidden data-source-check="' . $marker . '"></span>' );
	$fresh = taas_page_get( add_query_arg( 'taas_role', 'student', TAAS_Pages::url( 'student-application' ) ) );
	taas_page_check( 200 === wp_remote_retrieve_response_code( $fresh ) && ! str_contains( wp_remote_retrieve_body( $fresh ), $marker ), 'HTML file edits require an explicit import; WordPress remains authoritative' );
} finally {
	file_put_contents( $source_path, $original_source );
}

// Shared template edits must change the installer's fingerprint too.
$temporary = sys_get_temp_dir() . '/taas-shared-form-' . bin2hex( random_bytes( 6 ) );
mkdir( $temporary . '/pages/partials', 0700, true );
$partial = $temporary . '/pages/partials/form.html';
try {
	file_put_contents( $partial, '<form>Original</form>' );
	$fingerprint = TAAS_DB::fingerprint( $temporary );
	file_put_contents( $partial, '<form>Updated</form>' );
	taas_page_check( $fingerprint !== TAAS_DB::fingerprint( $temporary ), 'Shared template changes update the schema/page fingerprint' );
} finally {
	unlink( $partial );
	rmdir( $temporary . '/pages/partials' );
	rmdir( $temporary . '/pages' );
	rmdir( $temporary );
}
