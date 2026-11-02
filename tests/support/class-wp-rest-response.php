<?php
/**
 * Stand-in for the WordPress REST response
 *
 * @package TAAS_Core
 */

/**
 * Holds response data so a test can read it back
 */
class WP_REST_Response {

	/**
	 * Response headers.
	 *
	 * @var array<string, string>
	 */
	private array $headers = array();

	/**
	 * Create a response.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status.
	 */
	public function __construct( private mixed $data = null, private int $status = 200 ) {}

	/**
	 * Read the response data.
	 *
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Read the HTTP status.
	 *
	 * @return int
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Set a response header.
	 *
	 * @param string $key   Header name.
	 * @param string $value Header value.
	 * @return void
	 */
	public function header( $key, $value ) {
		$this->headers[ $key ] = $value;
	}

	/**
	 * Read the response headers.
	 *
	 * @return array<string, string>
	 */
	public function get_headers() {
		return $this->headers;
	}
}
