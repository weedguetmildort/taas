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
	 * Course ids in the database, keyed by "semester|year|code|name"
	 *
	 * @var array<string, string>
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

		if ( preg_match( "/course_semester = (\d+) AND course_year = (\d+) AND course_code = '(.*)' AND course_name = '(.*)'$/", $query, $match ) ) {
			return $this->course_ids[ implode( '|', array_slice( $match, 1 ) ) ] ?? null;
		}

		return null;
	}

	/**
	 * Record the insert, and remember the course so a later matching row replaces it
	 *
	 * @param string $table Table name.
	 * @param array  $data  Column => value.
	 * @return int|false
	 */
	public function insert( $table, $data ) {
		$result = parent::insert( $table, $data );

		if ( false !== $result ) {
			$key = implode( '|', array( $data['course_semester'], $data['course_year'], $data['course_code'], $data['course_name'] ) );

			$this->course_ids[ $key ] = (string) ( count( $this->course_ids ) + 1 );
		}

		return $result;
	}
}
