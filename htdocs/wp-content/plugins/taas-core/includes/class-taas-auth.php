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
	public const ROLE_HR_ADMIN   = 'taas_hr_admin';

	/**
	 * Create TAAS roles and their display names
	 *
	 * @return array<string, string>
	 */
	public static function roles(): array {
		return array(
			self::ROLE_STUDENT    => __( 'TAAS Student', 'taas-core' ),
			self::ROLE_INSTRUCTOR => __( 'TAAS Instructor', 'taas-core' ),
			self::ROLE_HR_ADMIN   => __( 'TAAS HR Admin', 'taas-core' ),
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

		return array(
			'wp_user_id'    => $user->ID,
			'university_id' => self::university_id_for( $user ),
			'email'         => $user->user_email,
			'display_name'  => $user->display_name,
			'role'          => self::taas_role_for( $user ),
		);
	}

	/**
	 * Extract the university identifier for a WordPress user
	 *
	 * @param WP_User $user WordPress user object
	 * @return string University identifier, or '' if not resolvable
	 */
	private static function university_id_for( WP_User $user ): string {
		/**
		 * Filter the user meta key holding the university identifier
		 *
		 * @since 0.1.0
		 *
		 * @param string $meta_key Meta key to read
		 */
		$meta_key = apply_filters( 'taas_university_id_meta_key', 'shibboleth_account' );

		$value = get_user_meta( $user->ID, $meta_key, true );

		if ( ! empty( $value ) ) {
			return (string) $value;
		}

		if ( ! empty( $user->user_login ) ) {
			return (string) $user->user_login;
		}

		if ( ! empty( $_SERVER['REMOTE_USER'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_USER'] ) );
		}

		return '';
	}

	/**
	 * Return the TAAS role for a user, or '' if they have none
	 *
	 * @param WP_User $user WordPress user object
	 * @return string
	 */
	private static function taas_role_for( WP_User $user ): string {
		$ordered = array( self::ROLE_HR_ADMIN, self::ROLE_INSTRUCTOR, self::ROLE_STUDENT );

		foreach ( $ordered as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return $role;
			}
		}

		return '';
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
			self::ROLE_HR_ADMIN   => array( '11223344', 'test.hr@ufl.edu', 'Test HR Admin' ),
		);

		list( $university_id, $email, $name ) = $fixtures[ $role ];

		return array(
			'wp_user_id'    => null,
			'university_id' => $university_id,
			'email'         => $email,
			'display_name'  => $name,
			'role'          => $role,
		);
	}

	/**
	 * Check if the current user holds the given TAAS role
	 *
	 * @param string $role One of the ROLE_* constants
	 * @return bool
	 */
	public static function has_role( string $role ): bool {
		$user = self::current_user();
		return null !== $user && $user['role'] === $role;
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
