<?php
/**
 * Limit non-admin access to the application cycle's current stage
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Close every taas/v1 route to students, HR and professors outside their stage
 *
 * This runs on the whole namespace rather than per route, so a controller added
 * later (student submission, HR, exports) is closed by default. It only decides
 * whether one of the caller's stages is open: each route's permission callback
 * still decides whether their roles may use that route.
 */
class TAAS_Stage_Gate {

	/**
	 * The namespace this gate covers, with its slashes as a request route has them
	 */
	private const ROUTE_PREFIX = '/taas/v1/';

	/**
	 * Patterns for routes every signed-in user may GET in any stage, so pages can show a "closed" message
	 *
	 * Each route's permission callback still decides which roles may read it.
	 */
	private const ALWAYS_READABLE = array(
		'#^/taas/v1/me$#',
		'#^/taas/v1/application-cycle$#',
		// A student's own applications, so they can check their status after their stage closes
		'#^/taas/v1/student/application(/terms|/[1-5]/[0-9]{4})?$#',
	);

	/**
	 * The stage in which each non-admin role has access
	 */
	private const ROLE_STAGES = array(
		TAAS_Auth::ROLE_STUDENT    => TAAS_Cycle_Stage::Student_Application,
		TAAS_Auth::ROLE_HR         => TAAS_Cycle_Stage::Hr_Review,
		TAAS_Auth::ROLE_INSTRUCTOR => TAAS_Cycle_Stage::Professor_Ranking,
	);

	/**
	 * Hook the gate in ahead of each route's permission callback
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rest_request_before_callbacks', array( self::class, 'enforce' ), 10, 3 );
	}

	/**
	 * Replace the response with an error if this request is outside the caller's stage
	 *
	 * @param mixed           $response Response so far; an error if the params were invalid.
	 * @param array           $handler  Route handler, unused.
	 * @param WP_REST_Request $request  Current request.
	 * @return mixed
	 */
	public static function enforce( $response, $handler, $request ) {
		// WordPress matches routes case-insensitively, so /TAAS/V1/courses must not slip past
		$route = strtolower( $request->get_route() );

		if ( ! str_starts_with( $route, self::ROUTE_PREFIX ) ) {
			return $response;
		}

		// WordPress serves HEAD with the GET handler, so it is a read too
		if ( in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) && self::is_always_readable( rtrim( $route, '/' ) ) ) {
			return $response;
		}

		return self::closed_error() ?? $response;
	}

	/**
	 * Whether a route matches one of the ALWAYS_READABLE patterns
	 *
	 * @param string $route Lowercased route without a trailing slash.
	 * @return bool
	 */
	private static function is_always_readable( string $route ): bool {
		foreach ( self::ALWAYS_READABLE as $pattern ) {
			if ( preg_match( $pattern, $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the error for a caller whose stage is not active, or null if they may continue
	 *
	 * Nobody signed in is left to the route's permission callback, which returns 401.
	 *
	 * @return WP_Error|null
	 */
	public static function closed_error(): ?WP_Error {
		$user = TAAS_Auth::current_user();

		if ( null === $user || in_array( TAAS_Auth::ROLE_ADMIN, $user['roles'], true ) ) {
			return null;
		}

		try {
			$stage = ( new TAAS_Application_Cycle_Repository() )->current()?->active_stage();
		} catch ( RuntimeException | InvalidArgumentException $e ) {
			TAAS_Logger::error( $e->getMessage(), array( 'route' => 'stage-gate' ) );

			return new WP_Error(
				'taas_server_error',
				__( 'The request could not be completed.', 'taas-core' ),
				array( 'status' => 500 )
			);
		}

		// Someone with several roles may continue if any one of their stages is active
		foreach ( $user['roles'] as $role ) {
			if ( null !== $stage && ( self::ROLE_STAGES[ $role ] ?? null ) === $stage ) {
				return null;
			}
		}

		return new WP_Error(
			'taas_stage_closed',
			__( 'This part of the system is not open right now.', 'taas-core' ),
			array(
				'status' => 403,
				'stage'  => $stage?->label(),
			)
		);
	}
}
