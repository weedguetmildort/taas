<?php
/**
 * Fake $wpdb for the course import tests
 *
 * @package TAAS_Core
 */

/**
 * Fake $wpdb that also answers the importer's schema, professor and course lookups
 */
class TAAS_Course_Import_Fake_Wpdb extends TAAS_Fake_Wpdb {

	/**
	 * SHOW COLUMNS rows, keyed by full table name
	 *
	 * @var array<string, array[]>
	 */
	public $columns = array();

	/**
	 * Professors in the database, each array( ufid, name, email )
	 *
	 * @var array[]
	 */
	public $professors = array();

	/**
	 * Course ids already in the database
	 *
	 * @var string[]
	 */
	public $course_ids = array();

	/**
	 * Answer SHOW COLUMNS for whichever table the query names
	 *
	 * @param string $query  SQL.
	 * @param string $output Ignored.
	 * @return array
	 */
	public function get_results( $query, $output = null ) {
		$this->sql = $query;

		foreach ( $this->columns as $table => $rows ) {
			if ( str_contains( $query, "`{$table}`" ) ) {
				return $rows;
			}
		}

		return array();
	}

	/**
	 * Answer the professor lookup and the "does this course exist" check
	 *
	 * @param string $query SQL.
	 * @return string|null
	 */
	public function get_var( $query ) {
		if ( preg_match( "/professor_name = '(.*)' AND professor_email = '(.*)'$/", $query, $match ) ) {
			foreach ( $this->professors as list( $ufid, $name, $email ) ) {
				if ( $name === $match[1] && $email === $match[2] ) {
					return $ufid;
				}
			}

			return null;
		}

		if ( preg_match( "/course_id = '(.*)'$/", $query, $match ) ) {
			return in_array( $match[1], $this->course_ids, true ) ? '1' : null;
		}

		return null;
	}

	/**
	 * Record the insert, and remember the course so a later row with the same Section replaces it
	 *
	 * @param string $table Table name.
	 * @param array  $data  Column => value.
	 * @return int|false
	 */
	public function insert( $table, $data ) {
		$result = parent::insert( $table, $data );

		if ( false !== $result ) {
			$this->course_ids[] = (string) $data['course_id'];
		}

		return $result;
	}
}
