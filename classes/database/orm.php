<?php defined('SYSTEM_PATH') or die('No direct access');
/**
 * ORM Database
 *
 * This ORM (Object Relational Mapping) class helps to promote DRY (Don't Repeat 
 * Yourself) principles by handling common tasks for your models. Things like
 * fetching rows and CRUD (Create Replace Update Delete) functionality are 
 * automatically handled by this class - saving hundreds of lines of code!
 * 
 * One less known, (yet useful feature) is the emulated foreign key cascade 
 * delete. This uses the model's relationships to delete rows for non-ACID 
 * databases like MySQL MyISAM.
 * 
 * This class is based on the larger http://github.com/kohana/orm
 *
 * @package		MicroMVC
 * @author		David Pennington
 * @copyright	(c) 2010 MicroMVC Framework
 * @license		http://micromvc.com/license
 ********************************** 80 Columns *********************************
 */
class Database_ORM {

	// Model relationships
	public $has_one		= array();
	public $belongs_to	= array();
	public $has_many	= array();

	// Model information
	public $class		= NULL;
	public $table		= NULL;
	public $loaded		= FALSE;
	public $saved		= FALSE;
	public $order_by	= array();

	// Current object state
	protected $_object	= array();
	protected $_changed	= array();
	protected $_related	= array();

	// Table primary key
	protected $_primary_key	= 'id';

	// Database instance name
	protected $_db			= 'default';

	// Should deleted rows cascade into other tables?
	protected $_cascade_delete	= FALSE;

	// Set at runtime to the name of the last has_many model (to fetch into)
	protected $_as_object		= NULL;

	// Foreign key suffix
	protected $_foreign_key_suffix = '_id';

	// Static, global complied relationships (to save object instance memory)
	protected static $_aliases	= array();


	/**
	 * Prepares the model database connection and loads the object.
	 *
	 * @param mixed $id of row to find, or object row to load
	 */
	public function __construct($id = NULL)
	{
		// Set this model's name
		if ( ! $this->class)
		{
			$name = strtolower(get_class($this));

			// Remove Model_ from the front of the name
			if(substr($name, 0, 6) == 'model_')
			{
				$name = substr($name, 6);
			}

			$this->class = $name;
		}

		// Set the matching database table for this model
		if ( ! $this->table)
		{
			// Table name is the same as the object name
			$this->table = Inflector::pluralize($this->class);
		}

		if ( ! is_object($this->_db))
		{
			// Get database instance
			$this->_db = Database::instance($this->_db);
		}

		/*
		 * Only compile the aliases one time (for subsequent object instances)
		 */
		if(empty(self::$_aliases[$this->class]))
		{
			foreach ($this->belongs_to as $alias => $details)
			{
				$defaults['model']       = $alias;
				$defaults['foreign_key'] = $alias.$this->_foreign_key_suffix;

				// Save the relationship
				self::$_aliases[$this->class]['belongs_to'][$alias] = array_merge($defaults, $details);
			}

			foreach ($this->has_one as $alias => $details)
			{
				$defaults['model']       = $alias;
				$defaults['foreign_key'] = $this->class.$this->_foreign_key_suffix;

				// Save the relationship
				self::$_aliases[$this->class]['has_one'][$alias] = array_merge($defaults, $details);
			}

			foreach ($this->has_many as $alias => $details)
			{
				$defaults['model']       = Inflector::singularize($alias);
				$defaults['foreign_key'] = $this->class.$this->_foreign_key_suffix;
				$defaults['through']     = NULL;
				$defaults['far_key']     = Inflector::singularize($alias).$this->_foreign_key_suffix;

				// Save the relationship
				self::$_aliases[$this->class]['has_many'][$alias] = array_merge($defaults, $details);
			}
		}

		// Remove the object relations to save memory
		unset($this->has_many, $this->belongs_to, $this->has_one);

		// If a row id or database row is given - use that for this object
		if ($id !== NULL)
		{
			if (is_array($id))
			{
				// This row must have a primary key
				if(isset($id[$this->_primary_key]))
				{
					$this->_object = $id;

					// Object is considered saved until something is set
					$this->saved = $this->loaded = TRUE;
				}
			}
			else
			{
				// Passing the primary key

				// Set the object's primary key, but don't load it until needed
				$this->_object[$this->_primary_key] = $id;

				// Object is considered saved until something is set
				$this->saved = TRUE;
			}
		}
	}


	/**
	 * Set values from an array. This method should be used
	 * for loading several filtered values at once.
	 *
	 * @param mixed $values an array of key => val pairs
	 * @return object
	 */
	public function values($values)
	{
		foreach ($values as $column => $value)
		{
			$this->__set($column, $value);
		}

		return $this;
	}


	/**
	 * Saves the current object to the database (if needed).
	 *
	 * @return object
	 */
	public function save()
	{
		if (empty($this->_changed))
		{
			return $this;
		}

		$data = array();
		foreach ($this->_changed as $column)
		{
			// Compile changed data
			$data[$column] = $this->_object[$column];
		}

		// Primary key isn't empty and hasn't been changed so do an update
		if ( ! $this->empty_pk() AND ! isset($this->_changed[$this->_primary_key]))
		{
			$this->update($data);
		}
		else
		{
			$this->insert($data);
		}

		// All changes have been saved
		$this->_changed = array();

		return $this;
	}


	/**
	 * Deletes the given row $id (or current object pk()) from the database.
	 * If $_cascade_delete is TRUE then this will also destroy all related has_one and
	 * has_many rows this object owns (emulating FOREIGN KEY () ON DELETE CASCADE).
	 *
	 * @param int $id the row's primary key to delete
	 * @return int
	 */
	public function delete($id = NULL)
	{
		if ($id === NULL)
		{
			// Use the the primary key value
			$id = $this->pk();
		}

		if ( ! $id OR $id === '0')
		{
			throw new Exception('No '. $this->class .' ID given to delete!');
		}
		
		// Make sure to reset AR conditions
		$this->_db->clear();
		
		// Total removed rows
		$removed = 0;

		// If we should also remove all related database rows (for this row)
		if($this->_cascade_delete)
		{

			if(self::$_aliases[$this->class]['has_one'])
			{
				// Remove each matching row this object "has_one" of
				foreach(self::$_aliases[$this->class]['has_one'] as $model => $details)
				{
					// Get the model
					$model = $this->related($model);

					// Use this model's primary key value and foreign model's column
					$removed += $this->_db->where($details['foreign_key'])->delete($model->table, array($id));
				}
			}

			if(self::$_aliases[$this->class]['has_many'])
			{
				// Remove each matching row this object "has_many" of
				foreach(self::$_aliases[$this->class]['has_many'] as $model => $details)
				{

					if ($details['through'])
					{
						// Load the through-relationship model
						$through = 'Model_'. $details['through'];
						$through = new $through;
						
						// Grab the has_many "through" relationship table
						$table = $through->table;
					}
					else
					{
						// Get the model
						$model = 'Model_'. ($details['model']);
						$model = new $model;

						// Grab the has_many table
						$table = $model->table;
					}

					// Simple has_many relationship, search where target model's foreign key is this model's primary key
					$removed += $this->_db->where($details['foreign_key'])->delete($table, array($id));

				}
			}
		}

		// Last, delete this row also!
		$removed += $this->_db->where($this->_primary_key)->delete($this->table, array($id));
		
		// Return the number of rows removed
		return $removed;
	}


	/**
	 * Reloads the current object from the database.
	 *
	 * @return object
	 */
	public function reload()
	{
		$primary_key = $this->pk();

		// Replace the object and reset the object status
		$this->_object = $this->_changed = $this->_related = array();

		// Reset the key
		$this->_object[$this->_primary_key] = $primary_key;

		//Reload
		$this->load();

		return $this;
	}


	/**
	 * Tests if this object has a relationship to a different model.
	 *
	 * @param string $alias of the has_many "through" relationship
	 * @param object $model the related ORM model
	 * @return boolean
	 */
	public function has($alias, $model)
	{
		$foreign = self::$_aliases[$this->class]['has_many'][$alias]['foreign_key'];
		$far_key = self::$_aliases[$this->class]['has_many'][$alias]['far_key'];

		// Load the through-relationship model
		$through = 'Model_'. self::$_aliases[$this->class]['has_many'][$alias]['through'];
		$through = new $through;
		
		// Return count of matches as boolean
		return (bool) $this->_db->from($through->table)
		->where($foreign)->where($far_key)
		->count(array($this->pk(), $model->pk()), FALSE);
	}


	/**
	 * Adds a new relationship to between this model and another.
	 *
	 * @param string $alias of the has_many "through" relationship
	 * @param object $model the related ORM model
	 * @return object
	 */
	public function add($alias, $model)
	{
		/**
		 * The "through" table model might have it's own methods to run before the insert.
		 * So if specified, we should use *that model* to perform the action.
		 */

		// Load the through-relationship model
		$through = 'Model_'. self::$_aliases[$this->class]['has_many'][$alias]['through'];
		$through = new $through;

		// Set these values
		$through->{self::$_aliases[$this->class]['has_many'][$alias]['foreign_key']} = $this->pk();
		$through->{self::$_aliases[$this->class]['has_many'][$alias]['far_key']} = $model->pk();

		// Create a new row
		$through->save();
		
		return $this;
	}


	/**
	 * Removes a relationship between this model and another.
	 *
	 * @param string $alias of the has_many "through" relationship
	 * @param object $model the related ORM model
	 * @return boolean
	 */
	public function remove($alias, $model)
	{
		$where = array(
			self::$_aliases[$this->class]['has_many'][$alias]['foreign_key'] => $this->pk(),
			self::$_aliases[$this->class]['has_many'][$alias]['far_key'] => $model->pk(),
		);
		
		// Get the through model
		$through = 'Model_'. self::$_aliases[$this->class]['has_many'][$alias]['through'];
		$through = new $through;
		

		return $this->_db->delete($through->table, $where);
	}


	/**
	 * Loads the given model only if it hasn't been loaded yet and a primary key is specified
	 *
	 * @return object
	 */
	public function load()
	{
		if ( ! $this->loaded AND ! $this->empty_pk())
		{
			// Set where clause
			$this->where($this->table.'.'.$this->_primary_key);

			// Fetch row
			return $this->find(array($this->pk()));
		}
	}


	/**
	 * Returns the value of the primary key
	 *
	 * @return int $primary_key
	 */
	public function pk()
	{
		return $this->_object[$this->_primary_key];
	}


	/**
	 * Finds and loads a single database row into the object. If no
	 * ID is given then the first row will be returned. If any database
	 * query methods where called before this - they will be added
	 * to the query alowing more advanced searching.
	 *
	 * @param mixed $params an array of params for the statement
	 * @return object
	 */
	public function find(array $params = NULL)
	{
		// From this table
		$this->_db->from($this->table);

		// If a resulting row is found
		if ($results = $this->_db->fetch($params, FALSE) AND ! empty($results[0]))
		{
			$this->_object = $results[0];
			return $this->saved = $this->loaded = TRUE;
		}

		return FALSE;
	}



	/**
	 * Fetch the has_many results *or* this table's results
	 * (setup by the previous chained methods)
	 *
	 * @param array $params the array of values to bind into the SQL
	 * @return array|boolean
	 */
	public function fetch(array $params = NULL, $as_object = NULL)
	{
		return $this->pull('fetch', $params, $as_object);
	}


	/**
	 * Allows counting of has_many rows *or* rows in THIS table
	 *
	 * @param array $params the array of values to bind into the SQL
	 * @return int
	 */
	public function count(array $params = NULL)
	{
		return $this->pull('count', $params);
	}


	/**
	 * Handles pass-through to database methods.
	 *
	 * @param   string  method name
	 * @param   array   method arguments
	 * @return  mixed
	 */
	public function __call($method, array $args)
	{
		switch (count($args))
		{
			case 0:
				$this->_db->$method();
				break;
			case 1:
				$this->_db->$method($args[0]);
				break;
			case 2:
				$this->_db->$method($args[0], $args[1]);
				break;
			case 3:
				$this->_db->$method($args[0], $args[1], $args[2]);
				break;
			default:
				// Here comes the snail...
				call_user_func_array(array($this->_db, $method), $args);
				break;
		}

		return $this;
	}


	/**
	 * Handles retrieval of all model values and relationships.
	 *
	 * @param string $column the value to fetch
	 * @return mixed
	 */
	public function __get($column)
	{
		// Make sure this object row is loaded
		$this->load();

		if (array_key_exists($column, $this->_object))
		{
			return $this->_object[$column];
		}
		elseif (isset($this->_related[$column]))
		{
			// Return related model that has already been loaded
			return $this->_related[$column];
		}
		elseif (isset(self::$_aliases[$this->class]['belongs_to'][$column]))
		{

			// Get the model
			$model = $this->related($column);

			// Use this model's column and foreign model's primary key
			$col = $model->table.'.'.$model->_primary_key;
			$val = $this->_object[self::$_aliases[$this->class]['belongs_to'][$column]['foreign_key']];

			// Try to find the matching row
			$model->where($col)->find(array($val));

			// If found, return the model
			return ($model->loaded ? $model : FALSE);

		}
		elseif (isset(self::$_aliases[$this->class]['has_one'][$column]))
		{

			$model = $this->related($column);

			// Use this model's primary key value and foreign model's column
			$col = $model->table.'.'.self::$_aliases[$this->class]['has_one'][$column]['foreign_key'];
			$val = $this->pk();

			// Try to find the matching row
			$model->where($col)->find(array($val));

			// If found, return the model
			return ($model->loaded ? $model : FALSE);

		}
		elseif (isset(self::$_aliases[$this->class]['has_many'][$column]))
		{
			// Get the model name
			$model_name = 'Model_'. (self::$_aliases[$this->class]['has_many'][$column]['model']);
			$model = new $model_name;

			if (self::$_aliases[$this->class]['has_many'][$column]['through'])
			{
				// Load the through-relationship model
				$through = 'Model_'. self::$_aliases[$this->class]['has_many'][$column]['through'];
				$through = new $through;
				
				// We must be carful selecting columns in the right order
				$this->_db->select('"'.$through->table.'".*,"'.$model->table.'".*');

				// Join on through model's target foreign key (far_key) and target model's primary key
				$join_col1 = $through->table.'.'.self::$_aliases[$this->class]['has_many'][$column]['far_key'];
				$join_col2 = $model->table.'.'.$model->_primary_key;

				$this->_db->join($through->table, array($join_col1 => $join_col2));

				// Through table's source foreign key (foreign_key) should be this model's primary key
				$column = $through->table.'.'.self::$_aliases[$this->class]['has_many'][$column]['foreign_key'];

			}
			else
			{
				// Simple has_many relationship, search where target model's foreign key is this model's primary key
				$column = $model->table.'.'.self::$_aliases[$this->class]['has_many'][$column]['foreign_key'];
			}

			// Set the column join value
			$this->_db->where($column);

			// Set the table
			$this->_db->from($model->table);
			
			// Set order by clause
			if($model->order_by)
			{
				foreach($model->order_by as $column => $sort)
				{
					$this->_db->order_by($column, $sort);
				}
			}

			// Set the model to fetch from
			$this->_as_object = $model_name;

			return $this;
		}

		// Show object values
		print dump($this->_object);

		// Bad propery name
		throw new Exception('Missing value: '.get_class($this).'::'.$column);

	}


	/**
	 * Handles setting of all model values, and tracks changes between values.
	 *
	 * @param string $column the row property
	 * @param mixed $value the new value
	 */
	public function __set($column, $value)
	{
		// If this column is not already set to this value
		if ( ! array_key_exists($column, $this->_object) OR $this->_object[$column] !== $value)
		{
			// Save new value
			$this->_object[$column] = $value;

			// Data has changed
			$this->_changed[$column] = $column;

			// Object is no longer saved
			$this->saved = FALSE;
		}
	}


	/**
	 * Checks if object data is set.
	 *
	 * @param   string  column name
	 * @return  boolean
	 */
	public function __isset($column)
	{
		$this->load();
		return (array_key_exists($column, $this->_object) OR isset($this->_related[$column]));
	}


	/**
	 * Unsets object data.
	 *
	 * @param   string  column name
	 * @return  void
	 */
	public function __unset($column)
	{
		$this->load();
		unset($this->_object[$column], $this->_changed[$column], $this->_related[$column]);
	}


	/**
	 * Displays the primary key of a model when it is converted to a string.
	 *
	 * @return  string
	 */
	public function __toString()
	{
		return (string) $this->pk();
	}


	/**
	 * Returns the current row as an array
	 * @return array
	 */
	public function to_array()
	{
		$this->load();
		return $this->_object;
	}
	
	
	/**
	 * Returns the current row as an object
	 * @return array
	 */
	public function to_object()
	{
		$this->load();
		return (object) $this->_object;
	}
	
	
	/**
	 * Returns whether or not primary key is empty
	 *
	 * @return bool
	 */
	protected function empty_pk()
	{
		return (empty($this->_object[$this->_primary_key]) OR $this->_object[$this->_primary_key] === '0');
	}


	/**
	 * Returns an ORM model for the given one-one related alias
	 *
	 * @param string $alias name
	 * @return object
	 */
	protected function related($alias)
	{
		if (isset($this->_related[$alias]))
		{
			return $this->_related[$alias];
		}
		elseif (isset(self::$_aliases[$this->class]['has_one'][$alias]))
		{
			$model = 'Model_'. self::$_aliases[$this->class]['has_one'][$alias]['model'];
			return $this->_related[$alias] = new $model;
		}
		elseif (isset(self::$_aliases[$this->class]['belongs_to'][$alias]))
		{
			$model = 'Model_'. self::$_aliases[$this->class]['belongs_to'][$alias]['model'];
			return $this->_related[$alias] = new $model;
		}
	}


	/**
	 * Returns the results from all the chained method calls
	 *
	 * @param string $method the database method to call
	 * @param array $params the optional array of params
	 * @return mixed
	 */
	protected function pull($method, array $params = NULL, $as_object = NULL)
	{

		/*
		 * If we are pulling results from a related "has_many" model then
		 * this _as_object will have been set by the __get() method. If it
		 * is not, then we know that we are running this query on THIS model.
		 */
		if ($this->_as_object)
		{
			// Add this Object's ID to the params for the JOIN done in __get($alias)
			$params = array_merge(array($this->pk()), (array) $params);
		}
		else
		{
			// It is a count on THIS table
			$this->_db->from($this->table);
		}

		// If no object is given, we need to check if a relation added one
		if($as_object === NULL)
		{
			// Capture value
			$as_object = $this->_as_object;
		}
		elseif($as_object === TRUE)
		{
			// If TRUE, then use this object
			$as_object = get_class($this);
		}

		// Clear the "has_many model" in which to load results
		$this->_as_object = NULL;

		// Return the total rows (count) OR result rows (fetch)
		return $this->_db->$method($params, ($method == 'count' ? FALSE : $as_object));

	}


	/**
	 * Callback invoked before the row is insert.
	 * Can be extended by the child model.
	 *
	 * @param $data the array of columns being updated
	 * @return array
	 */
	protected function insert(array $data = NULL)
	{
		// Execute Insert Statement
		$id = $this->_db->insert($this->table, $data);

		// Load the insert id as the primary key
		$this->_object[$this->_primary_key] = $id;

		// Object is now loaded and saved
		$this->loaded = $this->saved = TRUE;
	}


	/**
	 * Callback invoked before the row is updated.
	 * Can be extended by the child model.
	 *
	 * @param $data the array of columns being updated
	 * @return array
	 */
	protected function update(array $data = NULL)
	{
		// Execute Update Statement
		$this->_db->update($this->table, $data, array($this->_primary_key => $this->pk()));

		// Object has been saved
		$this->saved = TRUE;
	}


}
