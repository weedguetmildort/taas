<?php
/**
 * Add a students row for every student who signed in before sign-in added one.
 *
 * Signing in now adds the row (TAAS_Shibboleth::sync()), but students who
 * signed in earlier and do not come back would stay missing. Their UFID and
 * student role are already on their WordPress account, from the same sync.
 * Existing rows are left unchanged, so running it again changes nothing.
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

return function ( wpdb $wpdb ): void {
	// The schema upgrade check runs migrations in a database with only TAAS tables
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->users ) ) ) !== $wpdb->users ) {
		return;
	}

	$accounts = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT u.user_login, u.user_email, u.display_name, ufid.meta_value AS ufid,'
			. ' fn.meta_value AS first_name, ln.meta_value AS last_name'
			. ' FROM %i u'
			. ' JOIN %i ufid ON ufid.user_id = u.ID AND ufid.meta_key = %s'
			. ' JOIN %i caps ON caps.user_id = u.ID AND caps.meta_key = %s'
			. ' LEFT JOIN %i fn ON fn.user_id = u.ID AND fn.meta_key = %s'
			. ' LEFT JOIN %i ln ON ln.user_id = u.ID AND ln.meta_key = %s'
			. ' WHERE caps.meta_value LIKE %s'
			. ' ORDER BY u.ID',
			$wpdb->users,
			$wpdb->usermeta,
			TAAS_Auth::UFID_META_KEY,
			$wpdb->usermeta,
			$wpdb->get_blog_prefix() . 'capabilities',
			$wpdb->usermeta,
			'first_name',
			$wpdb->usermeta,
			'last_name',
			'%' . $wpdb->esc_like( '"' . TAAS_Auth::ROLE_STUDENT . '"' ) . '%'
		),
		ARRAY_A
	);

	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( esc_html( 'Reading student accounts failed: ' . $wpdb->last_error ) );
	}

	$students = new TAAS_Student_Repository();

	foreach ( (array) $accounts as $account ) {
		$ufid = TAAS_Auth::valid_ufid( (string) $account['ufid'] );

		// Sign-in only saves valid UFIDs, so anything else was not written by TAAS
		if ( '' === $ufid ) {
			continue;
		}

		$students->add_if_missing(
			new TAAS_Student(
				$ufid,
				TAAS_Shibboleth::student_name( (string) $account['display_name'], (string) $account['user_login'], (string) $account['first_name'], (string) $account['last_name'] ),
				TAAS_Shibboleth::student_email( (string) $account['user_email'], array() )
			)
		);
	}
};
