<?php
/**
 * Authenticate and resolve role for the TAAS plugins
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wrap the Shibboleth plugin so no other TAAS code touches it directly
 */
class TAAS_Auth {

	public const ROLE_STUDENT    = 'taas_student';
	public const ROLE_INSTRUCTOR = 'taas_instructor';
	public const ROLE_HR         = 'taas_hr';
	public const ROLE_ADMIN      = 'taas_admin';

	/**
	 * TAAS roles from highest to lowest; the first one a user holds is their primary role
	 */
	public const ROLE_PRIORITY = array( self::ROLE_ADMIN, self::ROLE_HR, self::ROLE_INSTRUCTOR, self::ROLE_STUDENT );

	/**
	 * WordPress role that counts as TAAS admin without being assigned it
	 */
	public const WP_ADMIN_ROLE = 'administrator';

	/**
	 * User meta key holding the UFID saved from Shibboleth at sign-in
	 */
	public const UFID_META_KEY = 'taas_ufid';

	/**
	 * Create TAAS roles and their display names
	 *
	 * @return array<string, string>
	 */
	public static function roles(): array {
		return array(
			self::ROLE_STUDENT    => __( 'TAAS Student', 'taas-core' ),
			self::ROLE_INSTRUCTOR => __( 'TAAS Instructor', 'taas-core' ),
			self::ROLE_HR         => __( 'TAAS HR', 'taas-core' ),
			self::ROLE_ADMIN      => __( 'TAAS Admin', 'taas-core' ),
		);
	}

	/**
	 * Register the TAAS roles
	 *
	 * @return void
	 */
	public static function register_roles(): void {
		foreach ( self::roles() as $slug => $label ) {
			if ( null === get_role( $slug ) ) {
				add_role( $slug, $label, array( 'read' => true ) );
				TAAS_Logger::info( 'Registered role: ' . $slug );
			}
		}
	}

	/**
	 * Determine if local development mocking is active
	 *
	 * @return bool
	 */
	public static function is_local_dev(): bool {
		return defined( 'TAAS_LOCAL_DEV' ) && TAAS_LOCAL_DEV;
	}

	/**
	 * Determine role of the mock user in local dev
	 *
	 * @return string One of the ROLE_* constants
	 */
	public static function local_dev_role(): string {
		$valid = array_keys( self::roles() );

		/**
		 * Override query-param, local dev only
		 */
		if ( self::is_local_dev() && isset( $_GET['taas_role'] ) ) {
			$requested = 'taas_' . sanitize_key( wp_unslash( $_GET['taas_role'] ) );
			if ( in_array( $requested, $valid, true ) ) {
				return $requested;
			}
		}

		$configured = defined( 'TAAS_LOCAL_DEV_USER' ) ? 'taas_' . TAAS_LOCAL_DEV_USER : self::ROLE_STUDENT;

		return in_array( $configured, $valid, true ) ? $configured : self::ROLE_STUDENT;
	}

	/**
	 * Return the current authenticated user, or null if nobody is logged in
	 *
	 * @return array|null
	 */
	public static function current_user(): ?array {
		if ( self::is_local_dev() ) {
			return self::mock_user();
		}

		$user = wp_get_current_user();

		if ( ! $user || 0 === $user->ID ) {
			return null;
		}

		$roles = self::resolve_roles( (array) $user->roles );

		return array(
			'wp_user_id'    => $user->ID,
			'university_id' => self::university_id_for( $user ),
			'email'         => $user->user_email,
			'display_name'  => $user->display_name,
			'role'          => $roles[0] ?? '',
			'roles'         => $roles,
		);
	}

	/**
	 * Return the UFID saved for a WordPress user, or '' if none is saved
	 *
	 * Only the UFID Shibboleth sent at sign-in counts. The login name and the
	 * Shibboleth plugin's own meta (which holds the identity provider, "preset")
	 * are never used: every account would share them.
	 *
	 * @param WP_User $user WordPress user object
	 * @return string Eight-digit UFID, or ''
	 */
	private static function university_id_for( WP_User $user ): string {
		/**
		 * Filter the user meta key holding the university identifier
		 *
		 * @since 0.1.0
		 *
		 * @param string $meta_key Meta key to read
		 */
		$meta_key = apply_filters( 'taas_university_id_meta_key', self::UFID_META_KEY );

		return self::valid_ufid( (string) get_user_meta( $user->ID, $meta_key, true ) );
	}

	/**
	 * Return the value if it is an eight-digit UFID, or ''
	 *
	 * @param string $value Candidate UFID
	 * @return string
	 */
	public static function valid_ufid( string $value ): string {
		$value = trim( $value );

		return 1 === preg_match( '/^[0-9]{8}$/', $value ) ? $value : '';
	}

	/**
	 * Return every TAAS role in a list of WordPress roles, highest first
	 *
	 * WordPress administrators count as TAAS admin. Roles that are not TAAS
	 * roles are ignored.
	 *
	 * @param string[] $wp_roles WordPress role slugs
	 * @return string[] ROLE_* constants, in ROLE_PRIORITY order
	 */
	public static function resolve_roles( array $wp_roles ): array {
		if ( in_array( self::WP_ADMIN_ROLE, $wp_roles, true ) ) {
			$wp_roles[] = self::ROLE_ADMIN;
		}

		return array_values( array_intersect( self::ROLE_PRIORITY, $wp_roles ) );
	}

	/**
	 * Build the fake user used in local development
	 *
	 * @return array
	 */
	private static function mock_user(): array {
		$role = self::local_dev_role();

		$fixtures = array(
			self::ROLE_STUDENT    => array( '12345678', 'test.student@ufl.edu', 'Test Student' ),
			self::ROLE_INSTRUCTOR => array( '87654321', 'test.instructor@ufl.edu', 'Test Instructor' ),
			self::ROLE_HR         => array( '11223344', 'test.hr@ufl.edu', 'Test HR' ),
			self::ROLE_ADMIN      => array( '99887766', 'test.admin@ufl.edu', 'Test Admin' ),
		);

		list( $university_id, $email, $name ) = $fixtures[ $role ];

		return array(
			'wp_user_id'    => null,
			'university_id' => $university_id,
			'email'         => $email,
			'display_name'  => $name,
			'role'          => $role,
			'roles'         => array( $role ),
		);
	}

	/**
	 * Check if the current user holds the given TAAS role, among any others
	 *
	 * @param string $role One of the ROLE_* constants
	 * @return bool
	 */
	public static function has_role( string $role ): bool {
		$user = self::current_user();
		return null !== $user && in_array( $role, $user['roles'], true );
	}

	/**
	 * Check if anyone is authenticated at all
	 *
	 * @return bool
	 */
	public static function is_authenticated(): bool {
		return null !== self::current_user();
	}

	/**
	 * Return a REST permission callback that requires a given role
	 *
	 * @param string $role One of the ROLE_* constants
	 * @return callable
	 */
	public static function require_role( string $role ): callable {
		return static function () use ( $role ) {
			if ( self::has_role( $role ) ) {
				return true;
			}

			return new WP_Error(
				'taas_forbidden',
				__( 'You do not have permission to do that.', 'taas-core' ),
				array( 'status' => self::is_authenticated() ? 403 : 401 )
			);
		};
	}

	/**
	 * Return a REST permission callback that requires any TAAS role
	 *
	 * @return callable
	 */
	public static function require_authenticated(): callable {
		return static function () {
			if ( self::is_authenticated() ) {
				return true;
			}

			return new WP_Error(
				'taas_unauthorized',
				__( 'You must be signed in.', 'taas-core' ),
				array( 'status' => 401 )
			);
		};
	}
}
