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
class Database_MySQL extends Database {

	/**
	 * Change the connection charset
	 *
	 * @param $charset
	 * @return boolean
	 */
	public function set_charset($charset)
	{
		// Make sure the database is connected
		$this->connection or $this->connect();

		// Create the SQL
		$sql = 'SET NAMES '.$this->escape($charset);

		//Start the query timer
		$start = microtime(TRUE);
		
		// Execute a raw SET NAMES query
		$result = $this->connection->exec($sql);

		//Record the query, params, and time taken
		if($this->log_queries)
		{
			$this->queries[] = array($sql, array(), (microtime(TRUE) - $start));
		}
		
		return $result;
	}


	/**
	 * Show all tables in database that optionally match $like
	 */
	public function list_tables($like = NULL)
	{
		// Make sure the database is connected
		$this->connection or $this->connect();

		// Start benchmark
		$this->benchmark_start();

		// Create the SQL
		$sql = 'SHOW TABLES'. ($like ? ' LIKE ?' : '');

		//$like can have wild cards like "%value%"
		$statement = $this->query($sql, array($like));

		//Record the query with the time/memory taken
		$this->queries[] = array_merge(array('sql' => $sql), $this->benchmark_end());

		if( ! $statement) {
			return array();
		}

		//For each result we added it to the array
		$tables = array();
		$results = $statement->results(FALSE);

		foreach($results as $key => $table)
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


	/**
	 * Replace the identifiers with a `tick` for MySQL
	 *
	 * @param string $sql the SQL string
	 * @return string
	 */
	public function filter($sql)
	{
		return str_replace('"', '`', $sql);
	}

}
