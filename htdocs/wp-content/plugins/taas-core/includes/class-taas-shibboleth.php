<?php
/**
 * Sync TAAS identity and roles from Shibboleth attributes
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read UF Shibboleth attributes and keep each account's UFID and roles current
 *
 * The Shibboleth plugin creates the WordPress account and gives it a single
 * WordPress role. This class adds what TAAS needs on top: the UFID, saved to
 * user meta, and the TAAS roles that have a source of truth: student from the
 * affiliations attribute, instructor from the professors table. Those roles are
 * added and removed to match their source; TAAS HR and TAAS admin are left to
 * wp-admin.
 */
class TAAS_Shibboleth {

	/**
	 * User meta key holding the time of the last sync
	 */
	public const SYNCED_META_KEY = 'taas_shibboleth_synced';

	/**
	 * Seconds between syncs for a user who stays signed in
	 */
	private const SYNC_INTERVAL = 600;

	/**
	 * Affiliation values, lowercased, that give the student role
	 *
	 * UF sends them as e.g. "STUDENT$"; affiliations() normalizes that to "student".
	 */
	public const STUDENT_AFFILIATIONS = array( 'student' );

	/**
	 * Hook the sync into sign-in and into requests from signed-in users
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_login', array( self::class, 'on_login' ), 10, 2 );
		add_action( 'init', array( self::class, 'maybe_sync_current_user' ) );
	}

	/**
	 * Sync on every sign-in; the Shibboleth plugin signs users in through wp_signon()
	 *
	 * @param string  $user_login Login name, unused
	 * @param WP_User $user       User who signed in
	 * @return void
	 */
	public static function on_login( $user_login, $user ): void {
		if ( $user instanceof WP_User ) {
			self::sync( $user );
		}
	}

	/**
	 * Sync a signed-in user whose last sync is older than SYNC_INTERVAL
	 *
	 * The WordPress session outlives the sign-in by days, so this keeps roles
	 * current, and fills in the UFID for accounts that signed in before this
	 * class existed.
	 *
	 * @return void
	 */
	public static function maybe_sync_current_user(): void {
		if ( TAAS_Auth::is_local_dev() || ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();
		$last = (int) get_user_meta( $user->ID, self::SYNCED_META_KEY, true );

		if ( time() - $last < self::SYNC_INTERVAL ) {
			return;
		}

		self::sync( $user );
	}

	/**
	 * Save the UFID and update synced roles from the current Shibboleth session
	 *
	 * Does nothing when there is no Shibboleth session, when it carries no valid
	 * UFID, or when it belongs to someone other than this WordPress account.
	 *
	 * @param WP_User $user Account to update
	 * @return bool Whether the account was synced
	 */
	public static function sync( WP_User $user ): bool {
		$ufid = TAAS_Auth::valid_ufid( self::attribute( 'ufid' ) );

		if ( '' === $ufid || ! self::session_matches( $user->user_login, $user->user_email, self::attribute( 'glid' ), self::attribute( 'mail' ) ) ) {
			return false;
		}

		update_user_meta( $user->ID, TAAS_Auth::UFID_META_KEY, $ufid );

		$changes = self::role_changes( (array) $user->roles, self::synced_roles( $ufid ) );

		foreach ( $changes['add'] as $role ) {
			$user->add_role( $role );
		}

		foreach ( $changes['remove'] as $role ) {
			$user->remove_role( $role );
		}

		if ( $changes['add'] || $changes['remove'] ) {
			TAAS_Logger::info( 'Synced roles from Shibboleth.', array( 'user_id' => $user->ID ) + $changes );
		}

		update_user_meta( $user->ID, self::SYNCED_META_KEY, time() );

		return true;
	}

	/**
	 * Return whether each synced role should be held, keyed by role
	 *
	 * A role whose source cannot be read right now is left out, so a database
	 * error or a missing attribute never strips anyone's access.
	 *
	 * @param string $ufid Valid UFID of the account
	 * @return array<string, bool>
	 */
	private static function synced_roles( string $ufid ): array {
		$wanted  = array();
		$student = self::is_student( self::attribute( 'eduperson_affiliations' ), self::student_affiliations() );

		if ( null !== $student ) {
			$wanted[ TAAS_Auth::ROLE_STUDENT ] = $student;
		}

		try {
			$wanted[ TAAS_Auth::ROLE_INSTRUCTOR ] = null !== ( new TAAS_Professor_Repository() )->find( $ufid );
		} catch ( RuntimeException $e ) {
			TAAS_Logger::error( 'Could not check the professors table: ' . $e->getMessage() );
		}

		return $wanted;
	}

	/**
	 * Affiliation values that give the student role, lowercased
	 *
	 * @return string[]
	 */
	private static function student_affiliations(): array {
		/**
		 * Filter the affiliation values that give the student role
		 *
		 * @since 0.7.8.8.2
		 *
		 * @param string[] $values Lowercased values, without UF's trailing "$"
		 */
		$values = (array) apply_filters( 'taas_student_affiliations', self::STUDENT_AFFILIATIONS );

		return array_map( 'strtolower', array_map( 'strval', $values ) );
	}

	/**
	 * Whether the affiliations attribute makes this user a student
	 *
	 * @param string   $raw            Raw eduperson_affiliations value
	 * @param string[] $student_values Lowercased values that count as student
	 * @return bool|null Null when the attribute is empty, so the role is left alone
	 */
	public static function is_student( string $raw, array $student_values = self::STUDENT_AFFILIATIONS ): ?bool {
		$affiliations = self::affiliations( $raw );

		if ( ! $affiliations ) {
			return null;
		}

		return (bool) array_intersect( $affiliations, $student_values );
	}

	/**
	 * Normalize the affiliations attribute into lowercased values
	 *
	 * UF ends each value with "$" (e.g. "STUDENT$"), and Shibboleth joins
	 * multiple values with ";", so both separate values.
	 *
	 * @param string $raw Raw eduperson_affiliations value
	 * @return string[]
	 */
	public static function affiliations( string $raw ): array {
		$values = preg_split( '/[;$]/', strtolower( $raw ) );
		$values = array_map( 'trim', false === $values ? array() : $values );

		return array_values( array_unique( array_filter( $values, 'strlen' ) ) );
	}

	/**
	 * Work out which roles to add and remove so the account matches $wanted
	 *
	 * @param string[]            $current Roles the account holds now
	 * @param array<string, bool> $wanted  Whether each synced role should be held
	 * @return array{add: string[], remove: string[]}
	 */
	public static function role_changes( array $current, array $wanted ): array {
		$changes = array(
			'add'    => array(),
			'remove' => array(),
		);

		foreach ( $wanted as $role => $should_hold ) {
			$holds = in_array( $role, $current, true );

			if ( $should_hold && ! $holds ) {
				$changes['add'][] = $role;
			} elseif ( ! $should_hold && $holds ) {
				$changes['remove'][] = $role;
			}
		}

		return $changes;
	}

	/**
	 * Whether the Shibboleth session belongs to this WordPress account
	 *
	 * The Shibboleth plugin names accounts after the GatorLink ID. Accounts that
	 * existed before it and were combined by email match on email instead.
	 *
	 * @param string $login WordPress login name
	 * @param string $email WordPress account email
	 * @param string $glid  GatorLink ID from Shibboleth
	 * @param string $mail  Email from Shibboleth
	 * @return bool
	 */
	public static function session_matches( string $login, string $email, string $glid, string $mail ): bool {
		if ( '' !== $glid && 0 === strcasecmp( $glid, $login ) ) {
			return true;
		}

		return '' !== $mail && 0 === strcasecmp( $mail, $email );
	}

	/**
	 * Read one Shibboleth attribute for the current request, or '' if absent
	 *
	 * Goes through the Shibboleth plugin so its attribute prefix setting
	 * (REDIRECT_UFShib_ on the server) applies. Always '' in local development.
	 *
	 * @param string $name Attribute name, e.g. ufid
	 * @return string
	 */
	public static function attribute( string $name ): string {
		if ( TAAS_Auth::is_local_dev() || ! function_exists( 'shibboleth_getenv' ) ) {
			return '';
		}

		return trim( (string) shibboleth_getenv( $name ) );
	}

	/**
	 * Split a multi-valued attribute; Shibboleth joins values with semicolons
	 *
	 * @param string $value Raw attribute value
	 * @return string[]
	 */
	public static function parse_values( string $value ): array {
		$values = array_map( 'trim', explode( ';', $value ) );

		return array_values( array_unique( array_filter( $values, 'strlen' ) ) );
	}

	/**
	 * Hide all but the last two digits of a UFID
	 *
	 * @param string $ufid UFID to mask
	 * @return string Masked UFID, or '' if it is not a valid UFID
	 */
	public static function mask_ufid( string $ufid ): string {
		$ufid = TAAS_Auth::valid_ufid( $ufid );

		return '' === $ufid ? '' : str_repeat( '*', 6 ) . substr( $ufid, -2 );
	}

	/**
	 * Describe the current user's own Shibboleth session, for the status route
	 *
	 * @param WP_User $user Current user
	 * @return array<string, mixed>
	 */
	public static function diagnostics( WP_User $user ): array {
		$glid         = self::attribute( 'glid' );
		$affiliations = self::attribute( 'eduperson_affiliations' );

		return array(
			'plugin_active'   => function_exists( 'shibboleth_getenv' ),
			'glid'            => $glid,
			'ufid'            => self::mask_ufid( self::attribute( 'ufid' ) ),
			'affiliations'    => self::parse_values( $affiliations ),
			'is_student'      => self::is_student( $affiliations, self::student_affiliations() ),
			'session_matches' => self::session_matches( $user->user_login, $user->user_email, $glid, self::attribute( 'mail' ) ),
			'ufid_saved'      => self::mask_ufid( (string) get_user_meta( $user->ID, TAAS_Auth::UFID_META_KEY, true ) ),
			'last_synced'     => (int) get_user_meta( $user->ID, self::SYNCED_META_KEY, true ),
		);
	}
}
