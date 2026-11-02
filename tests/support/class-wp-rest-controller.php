<?php
/**
 * Stand-in for the WordPress REST controller base class
 *
 * @package TAAS_Core
 */

/**
 * Just the properties TAAS controllers set
 */
class WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace;

	/**
	 * Route segment.
	 *
	 * @var string
	 */
	protected $rest_base;
}
