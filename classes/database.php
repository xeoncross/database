<?php defined('SYSTEM_PATH') or die('No direct access');
/**
 * PDORM Database Abstraction Layer and Object Relational Mapper
 *
 * After spending a several years using many PHP abstraction layers, I finally
 * consolidated my favorite parts into one small library that works with MySQL,
 * SQLite, and PostgreSQL. This library allows (and encourages) complex SQL to
 * be used instead of a massive, object-based mess of abstraction. However, it
 * also almost completely removes the need for writing standard simple queries
 * such as row selects (and counts) with joins and conditions thanks to the ORM
 * library. Most importantly, it requires SQL to be written in the prepared
 * statement format eliminating the need to quote values directly into your SQL.
 * This also hardens your code against SQL injection attacks and speeds up
 * repeat queries.
 *
 *
 * [[Prepared Statements]]
 *
 * This class is built on the assumption that everything is a prepared
 * statement. Therefore, you must pass all final SQL params to the fetch(),
 * query(), or statement->execute() methods to insure secure code.
 *
 * ESCAPING VALUES DIRECTLY INTO YOUR QUERIES IS NOT RECOMMENDED!
 *
 *
 * [[Quoting]]
 *
 * Use single-quotes, not double-quotes, around string literals in SQL. This is
 * what the SQL standard requires and PostgreSQL, SQLite, and MySQL all uphold
 * this.
 *
 * SQL uses "double-quotes" around identifiers (column or table names) that
 * contain special characters or which are keywords. So double-quotes are a way
 * of escaping identifier names. Only MySQL breaks this standard by using the
 * `tick` instead. However, you should still use "double-quotes" to maintain
 * portability since this class fixes the problem by replacing "double-quotes"
 * with `ticks` when using MySQL.
 *
 * @package		MicroMVC
 * @author		David Pennington
 * @copyright	(c) 2010 MicroMVC Framework
 * @license		http://micromvc.com/license
 ********************************** 80 Columns *********************************
 */
abstract class Database
{
	public $type = NULL;
	public $connection = NULL;
	public $queries = array();
	public static $instances = array();

	
	/**
	 * Get a singleton instance of the Database object loading one if
	 * not already created.
	 *
	 * @param string the instance name
	 * @param array the configuration parameters
	 * @return object
	 */
	public static function instance($name = 'default', array $config = NULL)
	{
		if (isset(self::$instances[$name]))
		{
			return self::$instances[$name];
		}

		if ( ! $config)
		{
			throw new Exception('Database configuration not found for '. $name);
		}

		// Get the database type
		$driver = 'Database_'. current(explode(':', $config['dsn'], 2));

		// Create the database connection instance
		return self::$instances[$name] = new $driver($name, $config);
	}


	/**
	 * Connect to the database on creations
	 *
	 * @param string $name of connection
	 * @param array $config of array values
	 */
	protected function __construct($name, array $config)
	{
		// Force PDO to use exceptions for all errors
		$attrs = array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_PERSISTENT => ($config['persistent'] ? TRUE : FALSE)
		);

		// Create a new PDO connection
		$this->connection = new PDO($config['dsn'], $config['username'], $config['password'], $attrs);
	}


	/**
	 * Fetch an array of records from the database using the SQL given.
	 *
	 * @param string $sql query to run
	 * @param array $params for the prepared statement
	 * @param mixed $as_object name to return row as (FALSE for array)
	 * @param int $cache life of result set in seconds or FALSE to disable
	 * @return array
	 */
	public function fetch($sql, array $params = array(), $as_object = TRUE, $cache = FALSE)
	{
		// Try to get the cached results first
		if($cache)
		{
			$hash = $sql;
			foreach($params as $param)
			{
				$hash .= md5($param);
			}
			$hash = sha1($hash);

			if($results = cache::get($hash, $cache))
			{
				return $results;
			}
		}

		// Run the query
		$results = $this->query($sql, $params);

		// If we should fetch the results into an object
		if (is_string($as_object))
		{
			$results = $results->fetchAll(PDO::FETCH_ASSOC);
			foreach($results as $id => $row)
			{
				$results[$id] = new $as_object($row);
			}
		}
		elseif($as_object === TRUE)
		{
			$results = $results->fetchAll(PDO::FETCH_CLASS, 'stdClass');
		}
		else
		{
			$results = $results->fetchAll(PDO::FETCH_ASSOC);
		}

		// Should we cache the results?
		if($cache)
		{
			cache::set($hash, $results);
		}

		return $results;
	}


	/**
	 * Run a SQL query and return a PDO statement object
	 *
	 * @param string $sql query to run
	 * @param array $params the prepared query params
	 * @return object
	 */
	public function query($sql, $params = array())
	{
		$start = microtime(TRUE);
		
		// Prepare the query
		$statement = $this->prepare($sql);

		// Add the params and execute it
		$statement->execute($params);
		
		// Record the query and time taken
		$this->queries[] = array($sql, (microtime(TRUE) - $start));
		
		// Return the statement object
		return $statement;
	}


	/**
	 * Prepare an SQL query and return a PDO statement object
	 *
	 * @param string $sql query to run
	 * @return object
	 */
	public function prepare($sql)
	{
		// MySQL uses an incorrect SQL identifier character (the `tick)
		if($this->type == 'mysql')
		{
			$sql = str_replace('"', '`', $sql);
		}

		// Get the database connection and prepare the statement
		return $this->connection->prepare($sql);
	}


	/**
	 * Execute an SQL statement and return the number of affected rows
	 *
	 * @param string $sql query to run
	 * @return int
	 */
	public function exec($sql)
	{
		// MySQL uses an incorrect SQL identifier character (the `tick)
		if($this->type == 'mysql')
		{
			$sql = str_replace('"', '`', $sql);
		}

		$start = microtime(TRUE);

		// Get the database connection and prepare the statement
		$result = $this->connection->exec($sql);
		
		// Record the query and time taken
		$this->queries[] = array($sql, (microtime(TRUE) - $start));
	}


	/**
	 * Count the number of rows affected by the delete
	 *
	 * @param string $sql query to run
	 * @param array $params the prepared query params
	 * @return int
	 */
	public function delete($sql, array $values = array())
	{
		return $this->query($sql, $values)->rowCount();
	}


	/**
	 * Count the number of rows found in the SELECT
	 *
	 * @param string $sql query to run
	 * @param array $params the prepared query params
	 * @return int
	 */
	public function count($sql, array $values = array())
	{
		return $this->query($sql, $values)->fetchColumn();
	}


	/**
	 * Builds an INSERT statement using the values provided
	 *
	 * @param string $table the table name
	 * @param array $data the column => value pairs
	 * @return int
	 */
	public function insert($table, array $data = array())
	{
		// Start the insert query
		$sql = 'INSERT INTO "'. $table .'" ("'. implode('", "', array_keys($data)). '") ';

		// Add the value placeholders
		$sql .= ' VALUES ('. rtrim(str_repeat('?,', count($data)), ','). ')';

		// Run the query
		$this->query($sql, array_values($data));

		// Return the new row's Id
		return $this->connection->lastInsertId();
	}


	/**
	 * Builds an UPDATE statement using the values provided.
	 * Create a basic WHERE section of a query using the format:
	 * array('column' => $value) or array("column = $value")
	 *
	 * @param string $table the table name
	 * @param array $data the column => value pairs
	 * @param array $where the array of where conditions
	 * @return int
	 */
	public function update($table, array $data = array(), array $where = NULL)
	{
		// Start the query
		$sql = 'UPDATE "'. $table. '" SET ';

		// Add the columns
		$sql .= '"'. implode('" = ?, "', array_keys($data)). '" = ?';

		// Add the WHERE clauses to the SQL
		if($where)
		{
			$sql .= ' WHERE ';
			foreach ($where as $column => $value)
			{
				if(is_int($column))
				{
					$sql .= $column;
				}
				else
				{
					$sql .= $column. ' = ?';
					$data[] = $value;
				}
			}
		}

		// Run the statement
		$statement = $this->query($sql, array_values($data));

		// Return the number of rows updated
		return $statement->rowCount();
	}

	
	/**
	 * Close the connection
	 *
	 * @return boolean
	 */
	public function disconnect()
	{
		// Destroy the PDO object
		$this->connection = NULL;

		return TRUE;
	}
	
	
	/**
	 * Escape a dangerous value to use in SQL.
	 * Use prepared statements instead of this function.
	 *
	 * @param mixed $value to quote
	 * @return string
	 */
	public function escape($value)
	{
		//Don't quote NULL values
		if($value === NULL)
		{
			return 'NULL';
		}

		//Quote using the database-specific method
		return $this->connection->quote($value);
	}
	

	/**
	 * Implement decorator pattern over the database connection object
	 * to allow the user to make calls through this database class.
	 *
	 * @param string $method name to call
	 * @param array $args the args passed
	 * @return mixed
	 */
	public function __call($method, array $args = array())
	{
		if( ! method_exists($this->connection, $method))
		{
			throw new Exception('PDO::'.$method. ' does not exist');
		}
		
		switch (count($args))
		{
			case 0:
				return $this->connection->$method();
				break;
			case 1:
				return $this->connection->$method($args[0]);
				break;
			case 2:
				return $this->connection->$method($args[0], $args[1]);
				break;
			case 3:
				return $this->connection->$method($args[0], $args[1], $args[2]);
				break;
			default:
				// Here comes the snail...
				return call_user_func_array(array($this->connection, $method), $args);
				break;
		}
	}


	/*
	 * Print out all of the queries run using <pre> tags
	 */
	public function print_queries()
	{
		if( ! $this->queries)
			return;
		
		foreach($this->queries as $query)
		{
			list($sql, $time) = $query;
			
			// Highlight the SQL
			print '<pre>'.h($sql). "\n/* Query Time: ". round($time, 5)." */</pre>\n\n";
		}
	}


	/**
	 * List all tables
	 *
	 * @param $like the optional filter
	 * @return array
	 */
	abstract public function list_tables($like = NULL);


	/**
	 * List all table columns
	 *
	 * @param $table the table
	 * @param $like the optional filter
	 * @return array
	 */
	abstract public function list_columns($table, $like = NULL);


	/**
	 * Change the connection charset
	 *
	 * @param $charset the new charset string
	 * @return boolean
	 */
	abstract public function set_charset($charset);
	
}

