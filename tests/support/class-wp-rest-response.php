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
	 * Create a response.
	 *
	 * @param mixed $data Response data.
	 */
	public function __construct( private mixed $data ) {}

	/**
	 * Read the response data.
	 *
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}
}
