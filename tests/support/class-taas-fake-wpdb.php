<?php
/**
 * Fake $wpdb for the repository tests
 *
 * @package TAAS_Core
 */

/**
 * Minimal $wpdb that records what repositories ask of it
 */
class TAAS_Fake_Wpdb {

	/**
	 * Table prefix
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Error message the next check will see
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Id of the last auto-increment insert
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Row get_row() returns
	 *
	 * @var array|null
	 */
	public $row = null;

	/**
	 * Rows get_results() returns
	 *
	 * @var array
	 */
	public $rows = array();

	/**
	 * Value insert() returns
	 *
	 * @var int|false
	 */
	public $insert_result = 1;

	/**
	 * SQL passed to the last read
	 *
	 * @var string
	 */
	public $sql = '';

	/**
	 * Arguments passed to the last write
	 *
	 * @var array
	 */
	public $write = array();

	/**
	 * Every write, in order, each shaped like $write
	 *
	 * @var array[]
	 */
	public $writes = array();

	/**
	 * Value update() returns
	 *
	 * @var int|false
	 */
	public $update_result = 1;

	/**
	 * Substitute %i, %s and %d the way $wpdb->prepare() does
	 *
	 * @param string $query SQL with placeholders.
	 * @param mixed  ...$args Values, in order.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return preg_replace_callback(
			'/%[isd]/',
			static function ( $m ) use ( &$args ) {
				$value = array_shift( $args );
				return match ( $m[0] ) {
					'%i' => '`' . $value . '`',
					'%s' => "'" . $value . "'",
					default => (string) (int) $value,
				};
			},
			$query
		);
	}

	/**
	 * Record the query and return the canned row
	 *
	 * @param string $query SQL.
	 * @return array|null
	 */
	public function get_row( $query ) {
		$this->sql = $query;
		return $this->row;
	}

	/**
	 * Record the query and return the canned rows
	 *
	 * @param string $query SQL.
	 * @return array
	 */
	public function get_results( $query ) {
		$this->sql = $query;
		return $this->rows;
	}

	/**
	 * Every statement passed to query(), in order
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Statement query() should fail on, or null for none
	 *
	 * @var string|null
	 */
	public $fail_query = null;

	/**
	 * Record a raw statement such as START TRANSACTION
	 *
	 * @param string $sql Statement.
	 * @return int|false
	 */
	public function query( $sql ) {
		$this->queries[] = $sql;
		return $sql === $this->fail_query ? false : 0;
	}

	/**
	 * Record the insert
	 *
	 * @param string $table Table name.
	 * @param array  $data Column => value.
	 * @return int|false
	 */
	public function insert( $table, $data ) {
		$this->write    = array(
			'table' => $table,
			'data'  => $data,
		);
		$this->writes[] = $this->write;
		return $this->insert_result;
	}

	/**
	 * Record the update
	 *
	 * @param string $table Table name.
	 * @param array  $data Column => value.
	 * @param array  $where Column => value.
	 * @return int|false
	 */
	public function update( $table, $data, $where ) {
		$this->write    = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);
		$this->writes[] = $this->write;
		return $this->update_result;
	}
}
