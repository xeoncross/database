<?php defined('SYSTEM_PATH') or die('No direct access');
/**
 * MySQL Database Class
 *
 * Extends the Database Class to handle MySQL specific tasks.
 *
 * @package		MicroMVC
 * @author		David Pennington
 * @copyright	(c) 2010 MicroMVC Framework
 * @license		http://micromvc.com/license
 ********************************** 80 Columns *********************************
 */
class Database_MySQL extends Database
{
	public $type = 'mysql';
	
	/**
	 * Change the connection charset
	 *
	 * @param string $charset
	 * @return boolean
	 */
	public function set_charset($charset)
	{
		//Start the query timer
		$start = microtime(TRUE);
		
		// Execute a raw SET NAMES query
		$this->connection->exec('SET NAMES '.$this->quote($charset));

		//Record the query, params, and time taken
		$this->queries[] = array($sql, (microtime(TRUE) - $start));
	}


	/**
	 * Show all tables in database that optionally match $like
	 * 
	 * @param string $like the name of the table to search for
	 */
	public function list_tables($like = NULL)
	{
		// Create the SQL
		$sql = 'SHOW TABLES'. ($like ? ' LIKE ?' : '');

		//$like can have wild cards like "%value%"
		if( ! $results = $this->fetch($sql, array('%'. $like. '%'), FALSE))
		{
			return array();
		}

		//For each result we added it to the array
		$tables = array();
		foreach($results as $table)
		{
			$tables[] = current($table);
		}

		return $tables;
	}


	/**
	 * @todo build this
	 */
	public function list_columns($table, $like = NULL)
	{
		throw new Exception(__METHOD__.' is not supported by '.get_class($this));
	}

}
