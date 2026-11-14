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
	 * Professors in the database, each array( id, ufid, name, email )
	 *
	 * @var array[]
	 */
	public $professors = array();

	/**
	 * Professors added by the import, each column => value
	 *
	 * @var array[]
	 */
	public $professor_inserts = array();

	/**
	 * Course ids in the database, keyed by "semester|year|code|name|online"
	 *
	 * @var array<string, string>
	 */
	public $course_ids = array();

	/**
	 * Answer the professor lookup by email, or SHOW COLUMNS for whichever table the query names
	 *
	 * @param string $query  SQL.
	 * @param string $output Ignored.
	 * @return array
	 */
	public function get_results( $query, $output = null ) {
		$this->sql = $query;

		if ( preg_match( "/WHERE `professor_email` = '(.*)' ORDER BY/", $query, $match ) ) {
			foreach ( $this->professors as list( $id, $ufid, $name, $email ) ) {
				if ( $email === $match[1] ) {
					return array(
						array(
							'professor_id'    => (string) $id,
							'professor_ufid'  => $ufid,
							'professor_name'  => $name,
							'professor_email' => $email,
						),
					);
				}
			}

			return array();
		}

		foreach ( $this->columns as $table => $rows ) {
			if ( str_contains( $query, "`{$table}`" ) ) {
				return $rows;
			}
		}

		return array();
	}

	/**
	 * Answer the "does this course exist" check
	 *
	 * @param string $query SQL.
	 * @return string|null
	 */
	public function get_var( $query ) {
		if ( preg_match( "/course_semester = (\d+) AND course_year = (\d+) AND course_code = '(.*)' AND course_name = '(.*)' AND is_online = ([01])$/", $query, $match ) ) {
			return $this->course_ids[ implode( '|', array_slice( $match, 1 ) ) ] ?? null;
		}

		return null;
	}

	/**
	 * Add a professor, or record a course insert and remember it so a later matching row replaces it
	 *
	 * @param string $table Table name.
	 * @param array  $data  Column => value.
	 * @return int|false
	 */
	public function insert( $table, $data ) {
		if ( str_ends_with( $table, 'professors' ) ) {
			$this->insert_id           = count( $this->professors ) + 1;
			$this->professors[]        = array( $this->insert_id, $data['professor_ufid'], $data['professor_name'], $data['professor_email'] );
			$this->professor_inserts[] = $data;

			return 1;
		}

		$result = parent::insert( $table, $data );

		if ( false !== $result ) {
			$key = implode( '|', array( $data['course_semester'], $data['course_year'], $data['course_code'], $data['course_name'], $data['is_online'] ) );

			$this->course_ids[ $key ] = (string) ( count( $this->course_ids ) + 1 );
		}

		return $result;
	}
}
