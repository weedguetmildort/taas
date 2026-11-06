<?php
/**
 * Stand-in for the WordPress REST request
 *
 * @package TAAS_Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment

/**
 * Just the parts of a request TAAS code reads
 */
class WP_REST_Request implements ArrayAccess {

	/**
	 * Create a request.
	 *
	 * @param array  $params     Body and query parameters.
	 * @param array  $url_params Path parameters.
	 * @param string $method     HTTP method.
	 * @param string $route      Request route.
	 * @param array  $files      Uploaded files, shaped like $_FILES.
	 */
	public function __construct(
		private array $params = array(),
		private array $url_params = array(),
		private string $method = 'GET',
		private string $route = '/taas/v1/test',
		private array $files = array()
	) {}

	public function get_file_params() {
		return $this->files;
	}

	public function has_param( $key ) {
		return array_key_exists( $key, $this->params );
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function get_url_params() {
		return $this->url_params;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}

	public function offsetExists( $offset ): bool {
		return isset( $this->params[ $offset ] );
	}

	public function offsetGet( $offset ): mixed {
		return $this->get_param( $offset );
	}

	public function offsetSet( $offset, $value ): void {
		$this->params[ $offset ] = $value;
	}

	public function offsetUnset( $offset ): void {
		unset( $this->params[ $offset ] );
	}
}
