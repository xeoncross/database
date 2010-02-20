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
abstract class Database {

	// Configuration array
	protected $config			= array();

	// Database instances
	public static $instances	= array();

	// Raw server connection
	public $connection			= NULL;

	// Last PDOStatement Statement Object
	public $statement			= NULL;

	// Array of all queries run
	public $queries				= array();

	// Log all queries run?
	public $log_queries			= FALSE;
	
	// Last query run - for errors
	public $last_query			= NULL;

	// Array of statements objects created
	public $statements			= array();

	// Cache prepared statements for the remainder of the page?
	public $cache_statements	= FALSE;

	// Cache database results?
	public $cache_results		= FALSE;

	// Type of Database
	public $type				= NULL;

	// @todo The prefix to add to each table
	public $table_prefix		= '';

	//Should table/column names in queries be quoted?
	public $quote_identifiers	= TRUE;

	//Active Record Clauses
	protected $_ar_select		= '*';
	protected $_ar_from			= array();
	protected $_ar_join			= array();
	protected $_ar_where		= array();
	protected $_ar_having		= array();
	protected $_ar_group_by		= array();
	protected $_ar_order_by		= array();
	protected $_ar_limit		= NULL;
	protected $_ar_offset		= NULL;


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
		if (isset(Database::$instances[$name]))
		{
			return Database::$instances[$name];
		}
		
		if ( ! $config)
		{
			throw new Exception('Database configuration not found for '. $name);
		}

		// Set the driver class name
		$driver = 'Database_'.$config['type'];

		// Create the database connection instance
		return new $driver($name, $config);
	}


	/**
	 * Stores the database configuration locally and name the instance.
	 *
	 * @return  void
	 */
	protected function __construct($name, array $config)
	{
		// Store the config locally
		$this->config = $config;

		// Cache results?
		$this->cache_results = $config['cache_results'];

		// Cache statements?
		$this->cache_statements = $config['cache_statements'];

		// Cache statements?
		$this->log_queries = $config['log_queries'];
		
		// Set the type of database
		$this->type = $config['type'];

		// Store the database instance
		Database::$instances[$name] = $this;
	}


	/**
	 * Connect to the database using the configuration given
	 */
	public function connect()
	{
		if ($this->connection)
		{
			return;
		}

		// Extract the connection parameters, adding required variabels
		extract($this->config['connection'] + array(
			'dsn'        => '',
			'username'   => NULL,
			'password'   => NULL,
			'persistent' => FALSE,
		));

		// Clear the connection parameters for security
		unset($this->config);

		// Force PDO to use exceptions for all errors
		$attrs = array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			// Use PHP to emulate server-based prepared statements?
			//PDO::ATTR_EMULATE_PREPARES => TRUE,
		);

		// Use the custom PDO statement class
		$attrs[PDO::ATTR_STATEMENT_CLASS] = array(
			'Database_Statement', array($this)
		);

		if ( ! empty($persistent))
		{
			// Make the connection persistent
			$attrs[PDO::ATTR_PERSISTENT] = TRUE;
		}

		// Create a new PDO connection
		$this->connection = new PDO($dsn, $username, $password, $attrs);

		if ( ! empty($this->config['charset']))
		{
			// Set the character set
			$this->set_charset($this->config['charset']);
		}

		return $this;
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
	 * Escape a dangerous value to use in SQL. It is recomended
	 * that you use prepared statements instead.
	 *
	 * @param $value
	 * @return string
	 */
	public function escape($value)
	{
		// Make sure the database is connected
		$this->connection or $this->connect();

		//Don't quote NULL values
		if( $value === NULL ) {
			return 'NULL';
		}

		//Quote using the database-specific method
		return $this->connection->quote($value);
	}


	/**
	 * Compiles, runs, and process the current query and returns the resulting
	 * rows. This is a short-cut method to use instead of prepare(), execute().
	 *
	 * @param	string the table
	 * @param	boolean	return the query?
	 * @param	boolean	save the ORM data?
	 * @return	object
	 */
	public function fetch(array $params = NULL, $as_object = TRUE, $key = NULL)
	{
		// Build the query if not given
		$sql = $this->compile_select();

		// Prepare and run query
		$statement = $this->prepare($sql);

		// Bind params
		$statement->execute($params);

		// Return an array of results ordered by $key
		return $statement->results($as_object, $key);

	}


	/**
	 * Generate a database statement from the SQL
	 *
	 * @param $sql the optional SQL to use
	 * @return object
	 */
	public function prepare($sql = '')
	{
		// Make sure the database is connected
		$this->connection or $this->connect();

		//Build the query if not given
		$sql = $sql ? $sql : $this->compile_select();

		// Allow the filter to alter it
		$sql = $this->filter($sql);

		// Look for a cached version of this statement
		if($this->cache_statements AND isset($this->statements[$sql]))
		{
			return $this->statements[$sql];
		}

		//Record the last query
		$this->last_query = $sql;

		// Prepare statement
		$statement = $this->connection->prepare($sql);

		// Store it for the rest of this page request
		if($this->cache_statements)
		{
			$this->statements[$sql] = $statement;
		}

		// Return the statement object
		return $this->statement = $statement;
	}


	/**
	 * Run a query and return a statement
	 *
	 * @param $sql the optional SQL to use
	 * @param $params the optional params to run on the statement
	 * @return object
	 */
	public function query($sql = '', array $params = NULL, $save = FALSE)
	{
		// Make sure the database is connected
		$this->connection or $this->connect();

		//Build the query if not given
		$sql = $sql ? $sql : $this->compile_select($save);

		//Record the last query
		$this->last_query = $sql;

		// If there are params then this is a prepared statement
		if($params)
		{
			// Prepare statement
			$statement = $this->prepare($sql);

			// Run query
			$statement->execute($params);
		}
		else
		{
			// Allow the filter to alter it
			$sql = $this->filter($sql);

			// Run the query
			$statement = $this->connection->query($sql);
		}

		// Return the statement object
		return $this->statement = $statement;
	}


	/**
	 * Count all rows in the given query
	 *
	 * @param $params the optional params to run on the statement
	 * @return int
	 */
	public function count(array $params = NULL, $save = FALSE)
	{
		// Build SQL
		$sql = $this->compile_select($save, TRUE);

		//log_message(dump(__METHOD__, $this->_ar_where));

		// Count the rows of the result
		return $this->query($sql, $params)->fetchColumn();
	}


	/**
	 * Implement decorator pattern over the database connection object
	 * to allow the user to make calls through this database class.
	 *
	 * @param $function the function they are calling
	 * @param $args the args they passed
	 * @return mixed
	 */
	public function __call($method, array $args = array())
	{
		// Make sure the database is connected
		$this->connection or $this->connect();

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
	 * Returns the last insert ID for the row created
	 */
	public function insert_id()
	{
		return $this->connection->lastInsertId();
	}


	/*
	 * If someone tries to use this object as a string
	 * just return the last query.
	 */
	public function __toString()
	{
		return $this->last_query;
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
			list($sql, $params, $time) = $query;
			
			// Insert params into the string
			if($params)
			{
				$parts = explode('?', $sql);
				
				$sql = '';
				foreach($parts as $id => $part)
				{
					$sql .= $part. (isset($params[$id]) ? "'". h($params[$id]). "'" : '');
				}
			}
			
			// Build SQL string
			$sql = $sql. "\n/* Query Time: ". round($time * 1000, 2). 'ms */';
			
			// Highlight the SQL
			//print highlight_code($sql, FALSE). "\n\n";
			print '<pre>'.h($sql). "</pre>\n\n";
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


	/**
	 * Clear all set query pieces
	 * @return object
	 */
	public function clear() {
		$this->_ar_select	= '*';
		$this->_ar_from		= array();
		$this->_ar_join		= array();
		$this->_ar_where	= array();
		$this->_ar_having	= array();
		$this->_ar_group_by	= array();
		$this->_ar_order_by	= array();
		$this->_ar_limit	= NULL;
		$this->_ar_offset	= NULL;
		return $this;
	}


	/**
	 * Enclose the columns(s) with the proper identifier. This function
	 * will not alter column values that contain functions or identifiers.
	 *
	 * @param array|string $columns to add identifiers around
	 * @return string
	 */
	public function quote_columns($columns)
	{
		//print dump($columns);
		return $columns;
		
		//If we are not allowed to quote stuff (or it is already quoted)
		if( ! $this->quote_identifiers)
		{
			return $columns;
		}

		// Conver columns into an array
		if( ! is_array($columns))
		{
			// If this is a complex SELECT clause
			if(strpos($columns, '(') !== FALSE)
			{
				return $columns;
			}

			// Break apart the columns
			if(strpos($columns, ',') !== FALSE)
			{
				$columns = explode(',', $columns);
			}
			else
			{
				$columns = array($columns);
			}
		}

		$fields = array();
		foreach($columns as $column)
		{
			$column = trim($column);

			// If it is already quoted - or contains functions like "MAX()"
			if(strpos($column, '"') !== FALSE OR strpos($column, '(') !== FALSE)
			{
				$fields[] = $column;
				continue;
			}

			//Break up database.table.column names to quote the inside
			if(strpos($column, '.') !== FALSE)
			{
				$column = explode('.', $column);
				$column = implode('"."', $column);
			}

			// Wrap final string
			$column = '"'.$column.'"';

			// Fix any * that were quoted by accedent
			$column = str_replace('"*"', '*', $column);

			$fields[] = $column;
		}

		print dump($fields);
		return implode(',', $fields);

	}

	/**
	 * Enclose the table with the proper quote identifier. This
	 * function is to be used to handle JOIN and FROM table names
	 * which may be in array(table => alias) form.
	 *
	 * @param array|string $table to quote
	 * @return string
	 */
	public function quote_table($table) {

		//$table = 'name';
		//$table = 'the.name';
		//$table = array('the.name' => 'alias');

		$alias = NULL;
		if(is_array($table))
		{
			$alias = current($table);
			$table = key($table);
		}

		// Quote the table name
		$table = $this->quote_columns($table);

		// Return '"database"."table" AS t1'
		return $table. ($alias ? ' AS '. $alias : '');

	}


	/**
	 * Sets the table(s) from which the query will pull rows
	 *
	 * @param	string $table the table to use
	 * @param	string $alias the optional table alias to use
	 * @return	object
	 */
	public function from($table)
	{
		$this->_ar_from[] = $this->quote_table($table);
		return $this;
	}


	/**
	 * Generates the JOIN portion of the query
	 *
	 * @access	public
	 * @param	string	the table name
	 * @param	string	the join condition
	 * @param	string	the type of join
	 * @return	object
	 */
	public function join($table, array $condition = NULL, $type = NULL)
	{
		// If a condition array was passed
		if($condition)
		{
			// For more than one condition... ON t1.id = t2.id AND t1.name = t2.name
			$sep = (count($condition) > 1 ? ' AND ' : '');

			foreach($condition as $col => $col_2)
			{
				$on[] = $this->quote_columns($col). ' = '. $this->quote_columns($col_2).$sep;
			}

			// Assemble the JOIN statement
			$this->_ar_join[] = $type.' JOIN '.$this->quote_table($table).' ON '. implode(',', $on);
		}
		else
		{
			// A pre-built JOIN statement given
			$this->_ar_join[] = $table;
		}

		return $this;
	}



	/**
	 * Generates the WHERE/HAVING (condition) portion of the query.
	 *
	 * @param string $condition the SQL condition string
	 * @param array|string $values array of values for a IN() clause (or string operator)
	 * @return string
	 */
	public function condition($condition, $values = NULL)
	{
		/* Examples:
		SELECT ... FROM table...
			WHERE column = ?
			HAVING column > ?
			WHERE column IS NULL
			HAVING column IN (1, 2, 3)
			WHERE column NOT IN (1, 2, 3)
			HAVING column IN (SELECT column FROM t2)
			WHERE column IN (SELECT c3 FROM t2 WHERE c2 = table.column + 10)
			HAVING column BETWEEN ? AND ?
			WHERE column BETWEEN (SELECT c3 FROM t2 WHERE c2 = table.column + 10) AND ?
			HAVING EXISTS (SELECT column FROM t2 WHERE c2 > table.column)
		*/

		// If no value is given, default to "equals"
		$values = $values ? $values : '=';

		/*
		 * If an array of values is given then we are creating an IN/NOT IN
		 * clause. This is something that must be directly inserted into the
		 * query since prepared statements don't support one-to-many bound
		 * params yet.
		 */
		if(is_array($values))
		{
			// Escape each value and insert it into the SQL
			foreach($values as $id => $value)
			{
				$values[$id] = $this->escape($value);
			}

			// Join values into a string
			$values = '('.implode(',', $values).')';

			// If no space it given - then add the IN clause
			if(strpos($condition, ' ') === FALSE)
			{
				$condition = $this->quote_columns($condition). ' IN ';
			}

			// Add the value string
			$condition .= $values;
		}
		else
		{
			// Allow condition('table.column') shorthand to mean "table.column = ?"
			if(strpos($condition, ' ') === FALSE)
			{
				$condition = $this->quote_columns($condition). ' '. $values. ' ?';
			}
		}

		// Compile the WHERE/HAVING clause and wrap in a grouping
		return '( '.$condition.' )';

	}


	/**
	 * Generates a WHERE portion of the query.
	 *
	 * @param string $condition the SQL condition string
	 * @param array|string $values array of values for a IN() clause (or string operator)
	 * @return object
	 */
	public function where($condition, $values = NULL)
	{
		$this->_ar_where[] = array('AND', $this->condition($condition, $values));
		return $this;
	}


	/**
	 * Generates an "OR WHERE" portion of the query.
	 *
	 * @param string $condition the SQL condition string
	 * @param array|string $values array of values for a IN() clause (or string operator)
	 * @return object
	 */
	public function or_where($condition, $values = NULL)
	{
		$this->_ar_where[] = array('OR', $this->condition($condition, $values));
		return $this;
	}


	/**
	 * Generates a HAVING portion of the query.
	 *
	 * @param string $condition the SQL condition string
	 * @param array|string $values array of values for a IN() clause (or string operator)
	 * @return object
	 */
	public function having($condition, $values = NULL)
	{
		$this->_ar_having[] = array('AND', $this->condition($condition, $values));
		return $this;
	}


	/**
	 * Generates an "OR HAVING" portion of the query.
	 *
	 * @param string $condition the SQL condition string
	 * @param array|string $values array of values for a IN() clause (or string operator)
	 * @return object
	 */
	public function or_having($condition, $values = NULL)
	{
		$this->_ar_having[] = array('OR', $this->condition($condition, $values));
		return $this;
	}


	/**
	 * Generates a "GROUP BY" portion of the query.
	 *
	 * @param $column the column to group by
	 * @return object
	 */
	function group_by($column)
	{
		$this->_ar_group_by[] = $this->quote_columns($column);
		return $this;
	}


	/**
	 * Generates an "ORDER BY" portion of the query.
	 *
	 * @param $column the column to order by
	 * @param $direction the optional sorting direction
	 * @return object
	 */
	function order_by($column, $direction = FALSE)
	{
		$this->_ar_order_by[] = $this->quote_columns($column). ($direction ? ' '.$direction : '');
		return $this;
	}


	/**
	 * Sets the query LIMIT value
	 *
	 * @param $limit a numeric limit
	 * @param $offset an optional numeric offset
	 * @return object
	 */
	public function limit($limit = NULL, $offset = NULL)
	{
		$this->_ar_limit = $limit;
		if($offset)
		{
			$this->_ar_offset = $offset;
		}

		return $this;
	}


	/**
	 * Sets the query OFFSET value
	 *
	 * @param $offset a numeric offset
	 * @return object
	 */
	public function offset($offset=NULL)
	{
		$this->_ar_offset = $offset;
		return $this;
	}


	/**
	 * Generates the SELECT portion of the query
	 *
	 * @param $select the select SQL
	 * @return object
	 */
	public function select($select = '*')
	{
		$this->_ar_select = $this->quote_columns(trim($select, ','));
		return $this;
	}


	/**
	 * Compile the full SELECT statement based off of the methods called.
	 *
	 * @param boolean $save the ORM data?
	 * @return string
	 */
	public function compile_select($save = FALSE, $count = FALSE)
	{
		// Write the "select" portion of the query
		$sql = 'SELECT '. ($count ? 'COUNT(*) as count' : $this->_ar_select);

		// Write the "FROM" portion of the query
		$sql .= "\nFROM ". implode(',', $this->_ar_from);

		// Write the "JOIN" portion of the query
		if($this->_ar_join)
		{
			//Add the join clauses
			$sql .= "\n". implode("\n", $this->_ar_join);
		}

		//If there is a WHERE clause
		if($this->_ar_where)
		{
			foreach($this->_ar_where as $id => $where)
			{
				// If not the first row - add the AND/OR clause also
				$sql .= ($id == 0 ? "\nWHERE " : ' '.$where[0].' '). $where[1];
			}
		}

		//If there is a HAVING clause
		if($this->_ar_having)
		{
			foreach($this->_ar_having as $id => $having)
			{
				// If not the first row - add the AND/OR clause also
				$sql .= ($id == 0 ? "\nHAVING " : ' '.$having[0].' '). $having[1];
			}
		}

		// Write the "GROUP BY" portion of the query
		if ($this->_ar_group_by) {
			$sql .= "\nGROUP BY ". implode(',', $this->_ar_group_by);
		}

		// If we are NOT compling this for a COUNT() query
		if( ! $count)
		{
			// Write the "ORDER BY" portion of the query
			if ($this->_ar_order_by) {
				$sql .= "\nORDER BY ". implode(',', $this->_ar_order_by);
			}

			// Write the "LIMIT" portion of the query
			if ($this->_ar_limit)
			{
				$sql .= "\nLIMIT ". ($this->_ar_offset ? $this->_ar_offset. ', ' : ''). $this->_ar_limit;
			}
		}

		//Remove the AR data?
		if( ! $save) {
			$this->clear();
		}

		//print dump($sql);

		return $sql;
	}


	/**
	 * Allow filtering of the query before running it
	 * @param string $sql the SQL string
	 * @return string
	 */
	public function filter($sql)
	{
		return $sql;
	}


	/*
	 * CRUD Operations
	 */


	/**
	 * Creates a DELETE prepared statement and removes the matching row(s)
	 *
	 * @param string|array $table the table name
	 * @param array $values the values to bind (for the where condition)
	 * @param boolean $run the query?
	 * @return int
	 */
	public function delete($table, array $values = NULL, $run = TRUE)
	{
		// If there is no WHERE clause
		if(empty($this->_ar_where)) {
			return FALSE;
		}

		//Create the Delete SQL
		$sql = 'DELETE FROM '. $this->quote_table($table);

		//If there is a WHERE clause
		foreach($this->_ar_where as $id => $where)
		{
			// If not the first row - add the AND/OR clause also
			$sql .= ($id == 0 ? "\nWHERE " : ' '.$where[0].' '). $where[1];
		}

		// Clear delete
		$this->clear();

		// Allow the filter to alter it
		$sql = $this->filter($sql);

		if( ! $run)
		{
			return $sql;
		}

		// Run the statement
		$statement = $this->query($sql, $values);

		// Return the number of rows deleted
		return $statement->rowCount();
	}


	/**
	 * Generates the INSERT SQL using prepared statement syntax
	 *
	 * @param string|array $table the table name
	 * @param string|array $fields the fields of the table
	 * @return string
	 */
	public function insert_string($table, $fields)
	{
		if(is_string($fields))
		{
			// Select based insert
			return 'INSERT INTO '. $this->quote_table($table) .' '. $fields;
		}

		// Quote each field name
		foreach($fields as $id => $field)
		{
			$fields[$id] = $this->quote_columns($field);
		}

		$values = rtrim(str_repeat('?,', count($fields)), ',');
		$fields = implode(',', $fields);
		$table = $this->quote_table($table);

		// Column based insert
		return	"INSERT INTO $table ($fields) VALUES ($values)";

	}


	/**
	 * Builds an INSERT statement using the values provided
	 *
	 * @param string|array $table the table name
	 * @param string|array $data the column => value pairs
	 * @param boolean $run the query?
	 * @return mixed
	 */
	public function insert($table, $data, $run = TRUE)
	{
		/* Example INSERT Queries:
		INSERT INTO films DEFAULT VALUES
		INSERT INTO films (title, kind) VALUES ('Yojimbo', 'Drama');
		INSERT INTO films SELECT * FROM tmp_films WHERE date < '2004-05-07';
		*/

		// If it is an array of column => value sets
		if(is_array($data))
		{
			$columns = array_keys($data);
			$values = array_values($data);
		}
		else
		{
			// INSERT data is a subquery or function call
			$columns = $data;
			$values = NULL;
		}


		// Build SQL
		$sql = $this->insert_string($table, $columns);

		// Allow the filter to alter it
		$sql = $this->filter($sql);

		if( ! $run)
		{
			return $sql;
		}

		// Run the new rows ID
		if($this->query($sql, $values))
		{
			return $this->insert_id();
		}
	}


	/**
	 * Generates the UPDATE SQL using prepared statement syntax
	 *
	 * @param string|array $table the table name
	 * @param string|array $fields the fields of the table
	 * @return string
	 */
	public function update_string($table, $fields, array $where = NULL)
	{
		// Format each field set
		foreach($fields as $id => $field)
		{
			$fields[$id] = $this->quote_columns($field). ' = ?';
		}

		// Quote the table
		$sql = 'UPDATE '.$this->quote_table($table);

		// List fields to update
		$sql .= "\nSET ". implode(', ', $fields);

		//If there is a WHERE clause
		if( $where)
		{
			foreach($where as $id => $where)
			{
				// If not the first row - add the AND/OR clause also
				$sql .= ($id == 0 ? "\nWHERE " : ' '.$where[0].' '). $where[1];
			}
		}

		//Return the comepleted Query
		return $sql;
	}


	/**
	 * Builds an UPDATE statement using the values provided
	 *
	 * @param string|array $table the table name
	 * @param string|array $data the column => value pairs
	 * @param boolean $run the query?
	 * @return mixed
	 */
	public function update($table, $data, array $where = NULL, $run = TRUE)
	{
		// Get the row columns
		$columns = array_keys($data);
		$values = array_values($data);

		if($where)
		{

			// Run the clauses on the SQL
			foreach ($where as $condition => $value)
			{
				if(is_array($value))
				{
					// Add "in" clauses directly to the query
					$this->where($condition, $value);
				}
				else
				{
					// Add the condition
					$this->where($condition);
					
					// Add the WHERE value to the SQL params
					$values[] = $value;
				}
				
			}

			// Add the values to the SQL params (never mind, they should be in the $data array)
			//$values = array_merge($values, array_values($where));
		}

		// There must be a where clause
		if( ! $this->_ar_where)
		{
			return FALSE;
		}

		// Build SQL
		$sql = $this->update_string($table, $columns, $this->_ar_where);
		
		// Allow the filter to alter it
		$sql = $this->filter($sql);

		// Clear the AR data
		$this->clear();

		if( ! $run)
		{
			return $sql;
		}
		
		// Run the statement
		$statement = $this->query($sql, $values);

		// Return the number of rows updated
		return $statement->rowCount();
	}






	/**
	 * Fetch infomation about the fields in a given table. Performs a
	 * SELECT of one row in order to find column data.
	 *
	 * @todo implement this
	 * @param	string	$table
	 * @return	array
	 *
	public function field_data($table = NULL)
	{

		//Select any example row
		$result = $this->query('SELECT * FROM '. $this->quote_table($table). ' LIMIT 1');

		//PDO data types
		$types = array(
			PDO::PARAM_BOOL => 'bool',
			PDO::PARAM_NULL	=> 'null',
			PDO::PARAM_INT	=> 'int',
			PDO::PARAM_STR	=> 'string',
			PDO::PARAM_LOB	=> 'blob',
			PDO::PARAM_STMT	=> 'statement'	//Not used right now
		);

		//print dump($types);

		$columns = array();

		if($result && $number = $result->columnCount())
		{
			for($x=0;$x<$number;$x++)
			{
				//Get meta
				$column = $result->getColumnMeta($x);

				//print dump($column);

				//If the column lenght isn't set - default to ZERO
				$column['len'] = isset($column['len']) ? $column['len'] : 0;

				// translate the PDO type into a human form
				$column['type'] = $types[$column['pdo_type']];

				//Save type information
				$columns[$column['name']] = $column;
			}
		}

		//print dump($columns);

		return $columns;
	}
	*/


} // End Database
