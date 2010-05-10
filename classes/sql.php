<?php

class SQL {
	
	/*
	 * PostgreSQL/SQLite use a "double-quote" while MySQL uses a `tick`.
	 * However, the database class will convert this character as needed.
	 */
	//public $quote_identifier	= '"';
	
	
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
	 * Defaults to the "double-quote" as the column/table identifier.
	 *
	 * @param array|string $columns to add identifiers around
	 * @return string
	 */
	public function quote_columns($columns)
	{
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

		return implode(',', $fields);

	}

	/**
	 * Enclose the table with the proper quote identifier. This
	 * function is to be used to handle JOIN and FROM table names
	 * which may be in array(table => alias) form.
	 * 
	 * $table = 'name';
	 * $table = 'the.name';
	 * $table = array('the.name' => 'alias');
	 *
	 * @param array|string $table to quote
	 * @return string
	 */
	public function quote_table($table)
	{
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
	 * @param string $table the table to use
	 * @return object
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
	 * @return string
	 */
	public function __toString()
	{
		// Write the "select" portion of the query
		$sql = 'SELECT '. $this->_ar_select;

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
		if ($this->_ar_group_by)
		{
			$sql .= "\nGROUP BY ". implode(',', $this->_ar_group_by);
		}

		// Write the "ORDER BY" portion of the query
		if ($this->_ar_order_by)
		{
			$sql .= "\nORDER BY ". implode(',', $this->_ar_order_by);
		}

		// Write the "LIMIT" portion of the query
		if ($this->_ar_limit)
		{
			$sql .= "\nLIMIT ". ($this->_ar_offset ? $this->_ar_offset. ', ' : ''). $this->_ar_limit;
		}

		return $sql;
	}
}