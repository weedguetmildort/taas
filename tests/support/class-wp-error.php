<?php
/**
 * Stand-in for the WordPress error class
 *
 * @package TAAS_Core
 */

/**
 * Holds an error so a test can read it back
 */
class WP_Error {

	/**
	 * Create an error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Error data.
	 */
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}

	/**
	 * Read the error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * Read the error data.
	 *
	 * @return mixed
	 */
	public function get_error_data() {
		return $this->data;
	}
}
