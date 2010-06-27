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
	public $loaded		= FALSE;
	public $saved		= FALSE;
	// @todo
	//public $order_by	= array();

	// Current object state
	protected $_object	= array();
	protected $_changed	= array();
	protected $_related	= array();

	// Table primary key
	protected $_primary_key	= 'id';

	// Foreign key suffix
	protected $_foreign_key_suffix = '_id';

	// Database instance name
	protected $_db			= 'default';

	// Should deleted rows cascade into other tables?
	protected $_cascade_delete	= FALSE;

	// Static, global complied relationships (to save object instance memory)
	public static $_aliases	= array();


	/**
	 * Return the name of this class
	 * @return string
	 */
	public function name()
	{
		// Remove Model_ from the front of the name
		return strtolower(substr(get_class($this), 6));
	}

	/**
	 * Prepares the model database connection and loads the object.
	 *
	 * @param mixed $id of row to find, or object row to load
	 */
	public function __construct($id = NULL, $db = NULL)
	{
		// Get database instance from the passed object or from the instance
		$this->_db = ($db ? $db : Database::instance($this->_db));

		// Compile the aliases one time and save the relationship for subsequent object instances
		if(empty(self::$_aliases[$this->name()]))
		{
			foreach ($this->belongs_to as $alias => $details)
			{
				self::$_aliases[$this->name()]['belongs_to'][$alias] = array_merge(
				array('model' => $alias, 'foreign_key' => $alias.$this->_foreign_key_suffix),
				$details
				);
			}

			foreach ($this->has_one as $alias => $details)
			{
				self::$_aliases[$this->name()]['has_one'][$alias] = array_merge(
				array('model' => $alias, 'foreign_key' => $this->name().$this->_foreign_key_suffix),
				$details
				);
			}

			foreach ($this->has_many as $alias => $details)
			{
				$defaults = array(
					'model' => $alias, 
					'foreign_key' => $this->name().$this->_foreign_key_suffix,
					'through' => NULL
				);

				// Save the relationship
				self::$_aliases[$this->name()]['has_many'][$alias] = array_merge($defaults, $details);
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
		if ( ! $this->_changed)
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
	 * @param int $id the row's primary key to delete (optional)
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
			throw new Exception('No '. $this->name() .' ID given to delete!');
		}

		// Total removed rows
		$removed = 0;

		// If we should also remove all related database rows (for this object)
		if($this->_cascade_delete)
		{
			$removed = $this->delete_all_relations();
		}

		$sql = 'DELETE FROM "'. $this->name(). '" WHERE "'. $this->_primary_key. '" = ?';

		// Last, delete this row also!
		$removed += $this->_db->delete($sql, array($id));

		// Return the number of rows removed
		return $removed;
	}


	/**
	 * Delete all "has_many" and "has_one" foreign key relations for the given row $id
	 * (or current object pk()) while keeping the given row itself.
	 *
	 * @param int $id the row's primary key to delete (optional)
	 * @return int
	 */
	public function delete_all_relations($id = NULL)
	{
		if ($id === NULL)
		{
			// Use the the primary key value
			$id = $this->pk();
		}

		if ( ! $id OR $id === '0')
		{
			throw new Exception('No '. $this->name() .' ID given to delete!');
		}

		// Total removed rows
		$removed = 0;

		// If we should also remove all related database rows (for this object)
		if(self::$_aliases[$this->name()]['has_one'])
		{
			// Remove each matching row this object "has_one" of
			foreach(self::$_aliases[$this->name()]['has_one'] as $model => $details)
			{
				// Build the query
				$sql = 'DELETE FROM "'.$details['model'].'" WHERE "'.$details['foreign_key'].'" = ? LIMIT 1';
					
				// Remove rows
				$removed += $this->_db->delete($sql, array($id));
			}
		}

		if(self::$_aliases[$this->name()]['has_many'])
		{
			// Remove each matching row this object "has_many" of
			foreach(self::$_aliases[$this->name()]['has_many'] as $model => $details)
			{
				// Build the query
				$sql = 'DELETE FROM "'. ($details['through'] ? $details['through'] : $details['model'])
				.'" WHERE "'.$details['foreign_key'].'" = ?';
					
				// Remove where target model's foreign key is this model's primary key
				$removed += $this->_db->delete($sql, array($id));
			}
		}

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
	 * Tests if this object has a relationship with a another model.
	 *
	 * @param string $alias of the has_many ("through") relationship
	 * @param object $model the related ORM model
	 * @return boolean
	 */
	public function has($alias, $model)
	{
		if(empty(self::$_aliases[$this->name()]['has_many'][$alias]))
		{
			throw new Exception($alias. ' alias is not found.');
		}

		$details = self::$_aliases[$this->name()]['has_many'][$alias];

		$sql = 'SELECT COUNT(*) FROM "'. $alias['through']. '" WHERE "'
		. $details['model'].$this->_foreign_key_suffix. '" = ? AND "'
		. $details['foreign_key']. '" = ?';
			
		return $this->_db->count($sql, array($model->pk(), $this->pk()));
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
		if(empty(self::$_aliases[$this->name()]['has_many'][$alias]))
		{
			throw new Exception($alias. ' alias is not found.');
		}

		/**
		 * The "through" table model might have it's own methods to run before the insert.
		 * So if specified, we should use *that model* to perform the action.
		 */

		$alias = self::$_aliases[$this->name()]['has_many'][$alias];

		// Load the through-relationship model
		$through = 'Model_'. $alias['through'];
		$through = new $through;

		// Set these values
		$through->{$this->name().$this->_foreign_key_suffix} = $this->pk();
		$through->{$model->name().$this->_foreign_key_suffix} = $model->pk();

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
		if(empty(self::$_aliases[$this->name()]['has_many'][$alias]))
		{
			throw new Exception($alias. ' alias is not found.');
		}

		$alias = self::$_aliases[$this->name()]['has_many'][$alias];

		$sql = 'DELETE FROM "'. $alias['through']. '" WHERE "'
		. $model->name().$this->_foreign_key_suffix. '" = ? AND "'
		. $this->name().$this->_foreign_key_suffix. '" = ?';
			
		return $this->_db->count($sql, array($model->pk(), $this->pk()));
	}


	/**
	 * Loads the matching database row into this object based on the primary_key already set
	 *
	 * @return bool
	 */
	public function load()
	{
		if ($this->loaded)
		return;

		// Build the query
		$sql = 'SELECT * FROM "'. $this->name(). '" WHERE "'. $this->_primary_key. '" = ?';

		// If a resulting row is found
		if ($results = $this->_db->fetch($sql, array($this->pk()), FALSE) AND ! empty($results[0]))
		{
			$this->_object = $results[0];
			return $this->saved = $this->loaded = TRUE;
		}

		return FALSE;
	}


	/**
	 * Returns the value of the primary key
	 *
	 * @return int
	 */
	public function pk()
	{
		return isset($this->_object[$this->_primary_key]) ? $this->_object[$this->_primary_key] : NULL;
	}


	/**
	 * Fetch an array of objects from the database optionally limiting by
	 * certain column values.
	 *
	 * @param array $where an array of column conditions
	 * @param int $limit SQL result limit
	 * @param int $offset SQL result offset
	 * @return array|boolean
	 */
	public function fetch(array $where = NULL, $limit = NULL, $offset = NULL)
	{
		$sql = 'SELECT * FROM "'. $this->name(). '"';

		// Add column clauses
		if($where)
		{
			$sql .= ' WHERE '. implode(' = ? AND ', array_keys($where)). ' = ? ';
		}

		// If a limit/offset were given
		if($limit)
		{
			$sql .= ' LIMIT '. ( isset($offset) ? $offset. ',' : ''). $limit;
		}


		// Return the objects
		return $this->_db->fetch($sql, ($where ? array_values($where) : array()), get_class($this));
	}


	/**
	 * Count the number of objects in the database optionally limiting by
	 * certain column values.
	 *
	 * @param array $columns an array of column conditions
	 * @return int
	 */
	public function count(array $where = NULL)
	{
		$sql = 'SELECT COUNT(*) FROM "'. $this->name(). '"';

		// Add column clauses
		if($where)
		{
			$sql .= ' WHERE '. implode(' = ? AND ', array_keys($where)). ' = ? ';
		}

		// Return the objects
		return $this->_db->count($sql, ($where ? array_values($where) : array()));
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
		$count = FALSE;

		// Remove the count_ from the name
		if(substr($method, 0, 6) === 'count_')
		{
			$count = TRUE;
			$method = substr($method, 6);
		}

		if (isset(self::$_aliases[$this->name()]['has_many'][$method]))
		{
			// Fetch the alias
			$alias = self::$_aliases[$this->name()]['has_many'][$method];
				
			// Start the prepared statement value array
			$values = array($this->pk());
				
			if ($alias['through'])
			{
				/*
				 SELECT `alias`.* FROM `alias` LEFT JOIN `through` on
				 `through`.fk_id = `alias`.id WHERE `through`.this_id = ?
				 */

				// Build the select
				$sql = 'SELECT '. ($count ? 'COUNT(*)' : 'T2.*, T1.*'). ' FROM "'
				. $alias['model'].'" AS T1'
				. ' LEFT JOIN "'.$alias['through'].'" AS T2 on T2."'
				. $alias['model'].$this->_foreign_key_suffix.'" = T1."'
				. $this->_primary_key. '" WHERE T2."'. $this->name()
				. $this->_foreign_key_suffix. '" = ?';
			}
			else
			{
				// Build the select
				$sql = 'SELECT '. ($count ? 'COUNT(*)' : '*'). ' FROM "'.$alias['model'].'" WHERE "'
				.$this->name().$this->_foreign_key_suffix.'" = ?';
			}

			// Add column clauses
			if(isset($args[0]))
			{
				$sql .= ' AND '. implode(' = ? AND ', array_keys($args[0])). ' = ?';

				// Also add the values to the params
				foreach($args[0] as $value)
				{
					$values[] = $value;
				}
			}
				
			// If a limit/offset were given
			if(isset($args[1]))
			{
				$sql .= ' LIMIT '. ( isset($args[2]) ? $args[2]. ',' : ''). $args[1];
			}
				
			if($count)
			{
				return $this->_db->count($sql, $values, 'Model_'. $alias['model']);
			}
			else
			{
				return $this->_db->fetch($sql, $values, 'Model_'. $alias['model']);
			}
		}

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
		elseif (isset(self::$_aliases[$this->name()]['belongs_to'][$column]))
		{
			// Load the related model
			$model = $this->related($column);

			// If found, return the model
			return ($model->loaded ? $model : FALSE);
		}
		elseif (isset(self::$_aliases[$this->name()]['has_one'][$column]))
		{
			// Load the related model
			$model = $this->related($column);
				
			// If found, return the model
			return ($model AND $model->loaded ? $model : FALSE);

		}

		// Show object values
		//print dump($this->_object);

		// Bad propery name
		throw new Exception('Missing property: '.get_class($this).'::'.$column);
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
	 * @param string $column name
	 * @return boolean
	 */
	public function __isset($column)
	{
		$this->load();
		return (array_key_exists($column, $this->_object) OR isset($this->_related[$column]));
	}


	/**
	 * Unsets object data.
	 *
	 * @param string $column name
	 * @return void
	 */
	public function __unset($column)
	{
		$this->load();
		unset($this->_object[$column], $this->_changed[$column], $this->_related[$column]);
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
		elseif (isset(self::$_aliases[$this->name()]['has_one'][$alias]))
		{
			// Get the model name
			$model = self::$_aliases[$this->name()]['has_one'][$alias]['model'];
				
			// Build the query
			$sql = 'SELECT * FROM "'.$model.'" WHERE "'.$this->name().$this->_foreign_key_suffix.'" = ?';
				
			// Fetch the results
			if($results = $this->_db->fetch($sql, array($this->pk()), 'Model_'. $model))
			{
				return $this->_related[$alias] = $results[0];
			}
		}
		elseif (isset(self::$_aliases[$this->name()]['belongs_to'][$alias]))
		{
			$this->load();
				
			// Get the model name
			$model = self::$_aliases[$this->name()]['belongs_to'][$alias]['model'];
				
			// Build the query
			$sql = 'SELECT * FROM "'.$model.'" WHERE "'.$this->_primary_key.'" = ?';
				
			$key = $this->_object[$model.$this->_foreign_key_suffix];
				
			// Fetch the results
			if($results = $this->_db->fetch($sql, array($key), 'Model_'. $model))
			{
				return $this->_related[$alias] = $results[0];
			}
		}
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
		$id = $this->_db->insert($this->name(), $data);

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
		$this->_db->update($this->name(), $data, array($this->_primary_key => $this->pk()));

		// Object has been saved
		$this->saved = TRUE;
	}


}
