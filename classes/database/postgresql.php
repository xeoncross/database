<?php defined('SYSTEM_PATH') or die('No direct access');
/**
 * PostgreSQL Database
 *
 * Extends the Database Class to handle PostgreSQL specific tasks.
 *
 * @package		MicroMVC
 * @author		David Pennington
 * @copyright	(c) 2010 MicroMVC Framework
 * @license		http://micromvc.com/license
 ********************************** 80 Columns *********************************
 */
class Database_PostgreSQL extends Database {

	/**
	 * Change the connection charset
	 *
	 * @param $charset
	 * @return boolean
	 */
	public function set_charset($charset)
	{
		throw new Exception(__METHOD__.' is not implemented');
	}


	/**
	 * Show all tables in database that optionally match $like
	 */
	public function list_tables($like = NULL)
	{
		throw new Exception(__METHOD__.' is not implemented');
	}


	/**
	 * @todo build this
	 */
	public function list_columns($table, $like = NULL)
	{
		throw new Exception(__METHOD__.' is not implemented');
	}


	/**
	 * Extra filtering hook if needed
	 *
	 * @param string $sql the SQL string
	 * @return string
	 */
	public function filter($sql) {}

}
