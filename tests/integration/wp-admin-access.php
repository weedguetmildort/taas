<?php
/**
 * Verify dashboard access against real WordPress accounts and HTTP requests.
 *
 * Run with PHP inside the local container: /var/www/taas/tests/integration/wp-admin-access.php
 * Temporary accounts and their sessions are removed after the checks.
 *
 * @package TAAS_Core
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.WP.GlobalVariablesOverride
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! TAAS_Auth::is_local_dev() ) {
	throw new RuntimeException( 'Local development mode required.' );
}

/**
 * Fail a regression check clearly.
 *
 * @param bool   $condition Expected condition.
 * @param string $message Check description.
 * @throws RuntimeException When a regression check fails.
 */
function taas_admin_access_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

$created = array();
$site    = wp_parse_url( home_url() );
$host    = $site['host'] . ( isset( $site['port'] ) ? ':' . $site['port'] : '' );

try {
	foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'taas_student', 'taas_instructor', 'taas_hr', 'taas_admin' ) as $role ) {
		$id = wp_insert_user(
			array(
				'user_login' => 'taas_access_' . bin2hex( random_bytes( 8 ) ),
				'user_pass'  => wp_generate_password( 32 ),
				'role'       => $role,
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$created[] = $id;
		wp_set_current_user( $id );
		$allowed = in_array( $role, array( 'administrator', 'editor' ), true );
		taas_admin_access_check( TAAS_Auth::can_access_wp_admin() === $allowed, $role . ' dashboard permission' );
		taas_admin_access_check( apply_filters( 'show_admin_bar', true ) === $allowed, $role . ' toolbar permission' );
		taas_admin_access_check( false === apply_filters( 'show_admin_bar', false ), $role . ' respects a disabled toolbar' );

		$expiration = time() + 600;
		$token      = WP_Session_Tokens::get_instance( $id )->create( $expiration );
		$cookies    = array(
			AUTH_COOKIE      => wp_generate_auth_cookie( $id, $expiration, 'auth', $token ),
			LOGGED_IN_COOKIE => wp_generate_auth_cookie( $id, $expiration, 'logged_in', $token ),
		);
		foreach ( array( '/wp-admin/', '/wp-admin/profile.php', '/wp-admin/admin-ajax.php', '/wp-admin/admin-post.php' ) as $path ) {
			$response = wp_remote_get(
				'http://127.0.0.1' . $path,
				array(
					'headers'     => array( 'Host' => $host ),
					'cookies'     => $cookies,
					'redirection' => 0,
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( $response->get_error_message() );
			}
			$status   = wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( str_contains( $path, 'admin-ajax.php' ) || str_contains( $path, 'admin-post.php' ) ) {
				taas_admin_access_check( ( str_contains( $path, 'admin-ajax.php' ) ? 400 : 200 ) === $status && '' === $location, $role . ' can reach handler ' . $path );
			} elseif ( $allowed ) {
				taas_admin_access_check( 200 === $status, $role . ' can open ' . $path . ' (HTTP ' . $status . ')' );
			} else {
				taas_admin_access_check( 302 === $status && home_url( '/' ) === $location, $role . ' redirects from ' . $path );
			}
		}

		if ( 'taas_admin' === $role ) {
			$user = wp_get_current_user();
			$user->add_role( 'editor' );
			taas_admin_access_check( TAAS_Auth::can_access_wp_admin(), 'TAAS admin with WordPress editor role is allowed' );
			$user->remove_role( 'editor' );
			taas_admin_access_check( ! TAAS_Auth::can_access_wp_admin(), 'Removing WordPress editor role revokes dashboard access' );
		}
	}
	wp_set_current_user( 0 );
	taas_admin_access_check( ! TAAS_Auth::can_access_wp_admin() && ! apply_filters( 'show_admin_bar', true ), 'Anonymous visitors have no dashboard or toolbar permission' );
} finally {
	wp_set_current_user( 0 );
	foreach ( $created as $id ) {
		WP_Session_Tokens::get_instance( $id )->destroy_all();
		wp_delete_user( $id );
	}
}
