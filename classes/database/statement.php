<?php defined('SYSTEM_PATH') or die('No direct access');
/**
 * Database Statement
 *
 * This Object extends the PDOStatement class to provide more functionality.
 * Namely, caching of result sets.
 *
 * @package		MicroMVC
 * @author		David Pennington
 * @copyright	(c) 2010 MicroMVC Framework
 * @license		http://micromvc.com/license
 ********************************** 80 Columns *********************************
 */
class Database_Statement extends PDOStatement
{
	// Array of params for query hash
	public $params = array();

	// Database handle
	public $db;

	// The result set from the query
	public $results = NULL;

	// The final query + params hash
	public $hash = NULL;

	// The max-life of cached results (inherited from DB)
	public $cache_results = NULL;

	// Changes the statement to default to fetch as object instead of BOTH
	protected function __construct($db)
	{
		$this->db = $db;
		$this->cache_results = $db->cache_results;
	}


	/**
	 * Fetch an array of all resulting rows and optionally key them by
	 * a given column name. If a class name is given then the row data
	 * will be used to popluate an instance of that class.
	 *
	 * @param $as_object fetch rows as objects or as instances of the given class
	 * @param $key the optional key to order the array by
	 * @return array
	 */
	public function results($as_object = FALSE, $key = FALSE)
	{
		// If we already have these results
		if($this->results)
		{
			$results = $this->results;
		}
		elseif($as_object === TRUE)
		{
			$results = $this->fetchAll(PDO::FETCH_CLASS, 'stdClass');
		}
		else
		{
			$results = $this->fetchAll(PDO::FETCH_ASSOC);
		}

		// If we don't already have a cache for this - cache it
		if($results AND ! $this->results AND $this->cache_results AND $this->hash)
		{
			cache::set($this->hash, $results);
		}

		// If we should fetch the results into an object
		if (is_string($as_object))
		{
			foreach($results as $id => $row)
			{
				$results[$id] = new $as_object($row);
			}
		}

		if( ! $key)
		{
			return $results;
		}

		$sorted_results = array();

		if ($as_object)
		{
			foreach ($results as $row)
			{
				$sorted_results[$row->$key] = $row;
			}
		}
		else
		{
			foreach ($results as $row)
			{
				$sorted_results[$row[$key]] = $row;
			}
		}

		return $sorted_results;
	}



	/**
	 * Execute the given statement
	 * @param $params the array of params to bind
	 * @return boolean
	 */
	public function execute($params = NULL)
	{
		//If caching is enabled and this is a SELECT query
		if($this->cache_results AND substr($this->queryString, 0, 6) == 'SELECT')
		{

			if($params)
			{
				$this->params = $params + $this->params;
			}

			//Create a hash
			$hash = $this->queryString;

			if($this->params)
			{
				foreach($this->params as $param)
				{
					$hash .= md5($param);
				}
			}

			// Hash
			$this->hash = sha1($hash);

			// Try to get the cached results
			if($this->results = cache::get($this->hash, $this->cache_results))
			{
				return TRUE;
			}
		}

		//Start the query timer
		$start = microtime(TRUE);
		
		// Run query
		$result = parent::execute($params);
		
		// If we should log the queries
		if($this->db->log_queries)
		{
			// Add params
			$params = (array) $params + (array) $this->params;
			
			//Record the query, params, and time taken
			$this->db->queries[] = array($this->queryString, $params, (microtime(TRUE) - $start));
		}
		
		// Return status of result
		return $result;
	}


	/**
	 * Remove the cached results and re-execute the statement
	 *
	 * @param $params an array of the params to bind
	 * @return boolean
	 */
	public function refresh($params = NULL)
	{
		// Remove the current result set
		$this->results = NULL;

		//If caching is enabled
		if($this->cache_results AND $this->hash)
		{
			// Delete the entry in the cache
			cache::delete($this->hash);
		}

		// Re-request the data
		return $this->execute($params);
	}


	/**
	 * Set the value of a parameter in the query.
	 *
	 * @param $params a single param name (or an array(param => variables) to use)
	 * @param $value the variable to insert
	 * @param $type the type of variable (defaults to string)
	 */
	public function param($params = NULL, $value = NULL, $type = NULL)
	{
		// Force an array
		if( !is_array($params) ) {
			$params = array($params => $value);
		}

		// Save params to for cache hash
		$this->params = $params + $this->params;

		foreach( $params as $key => $value )
		{
			$this->bindValue($key, $value, $type);
		}
	}


	/**
	 * Bind a variable to a parameter in the query.
	 *
	 * @param $params a single param name (or an array(param => &variables) to bind)
	 * @param $value the variable to bind
	 * @param $type the type of variable (defaults to string)
	 */
	public function bind($params = NULL, & $value = NULL, $type = NULL)
	{
		// Force an array
		if( !is_array($params) ) {
			$params = array($params => & $value);
		}

		// If no type is given then PDO will default to PDO::PARAM_STR
		$type = $type ? $type : Database::PARAM_STRING;

		// Save params to for cache hash
		$this->params = $params + $this->params;

		foreach( $params as $key => & $value )
		{
			$this->bindParam($key, $value, $this->param_types[$type]);
		}
	}


	/**
	 * Return the SQL used to create this object
	 * @return string
	 */
	public function __toString()
	{
		return $this->queryString;
	}


	/**
	 * Number of rows in the result set
	 * @return int
	 */
	public function num_rows()
	{
		return $this->rowCount();
	}

}
