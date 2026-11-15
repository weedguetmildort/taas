<?php
/**
 * REST controller for CSV exports.
 *
 * @package TAAS_Core
 */

declare(strict_types=1);
defined( 'ABSPATH' ) || exit;

/**
 * Routes under taas/v1/exports, all admin-only.
 *
 *   GET /exports/{type}    one export for a term, as a CSV download
 *
 * The exports themselves are built in func-taas-exports.php.
 */
final class TAAS_REST_Export_Controller extends TAAS_REST_Controller {

	use TAAS_REST_Term_Params;

	/**
	 * Create the controller.
	 */
	public function __construct() {
		parent::__construct( 'exports' );
	}

	/**
	 * Register the routes, and send this controller's CSV responses as they are rather than as JSON.
	 *
	 * @return void
	 */
	public function register_routes() {
		$types = implode( '|', array_map( static fn( $type ) => preg_quote( $type, '#' ), array_keys( TAAS_EXPORT_TYPES ) ) );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<type>' . $types . ')',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $this->read_permission(),
					'args'                => $this->term_args(),
				),
			)
		);

		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_csv' ), 10, 3 );
	}

	/**
	 * GET /exports/{type}: one export for a term.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		return $this->respond(
			function () use ( $request ) {
				$term = $this->requested_term( $request );

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				$export = taas_export( $this->path_param( $request, 'type' ), $term[0], $term[1] );

				return new WP_REST_Response(
					$export['csv'],
					200,
					array(
						'Content-Type'        => 'text/csv; charset=utf-8',
						'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
						'Cache-Control'       => 'private, no-store',
					)
				);
			}
		);
	}

	/**
	 * Echo a successful export's CSV, where WordPress would otherwise JSON-encode it.
	 *
	 * Hooked to rest_pre_serve_request, which runs after the response's headers are sent.
	 *
	 * @param bool            $served  Whether another filter already sent the response.
	 * @param mixed           $result  Response to send.
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	public static function serve_csv( $served, $result, $request ): bool {
		$is_export = $result instanceof WP_REST_Response
			&& 200 === $result->get_status()
			&& is_string( $result->get_data() )
			&& str_starts_with( $request->get_route(), '/taas/v1/exports/' );

		if ( $served || ! $is_export ) {
			return (bool) $served;
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV download, not HTML.

		return true;
	}
}
