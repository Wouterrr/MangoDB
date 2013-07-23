<?php

/*
 * ----------------------------------------------------------------------------
 * "THE BEER-WARE LICENSE" (Revision 42):
 * Wouter Wiegmans wrote this file. As long as you retain this notice you
 * can do whatever you want with this stuff. If we meet some day, and you think
 * this stuff is worth it, you can buy me a beer in return - Wouter Wiegmans
 * ----------------------------------------------------------------------------
 */

abstract class Kohana_Mango implements Mango_Interface {

	/**
	 * @var  array  Extension config
	 */
	protected static $_cti;

	const CHANGE   = 0; // Pushes values into model - trigger changes (default)
	const EXTEND   = 1; // Returns empty model - only uses values to detect possible extension
	const CLEAN    = 2; // Pushes values into model - doesn't trigger changes

	const CHECK_FULL  = 0; // Full validation - local and embedded documents
	const CHECK_LOCAL = 1; // Full validation of local data only (not the embedded documents)
	const CHECK_ONLY  = 2; // Selective validation of supplied local fields only

	// Default maximum length of string fields, longer values are cut off.
	// You can overload this on a per field basis by defining a 'max_size' value in the field definition.
	const MAX_SIZE_STRING = 65536;
	

	/**
	 * Load an Mango model.
	 *
	 * @param   string  model name
	 * @param   array   values to pre-populate the model
	 * @param   boolean use values to extend only, don't prepopulate model
	 * @return  Mango
	 */
	public static function factory($name, array $values = NULL, $load_type = 0)
	{
		static $models;

		if ( $values)
		{
			$name = self::extend($name, $values);
		}

		if ( ! isset($models[$name]))
		{
			$class = 'Model_'.$name;

			$models[$name] = new $class;
		}

		// Create a new instance of the model by clone
		$model = clone $models[$name];

		if ( $values && $load_type !== Mango::EXTEND)
		{
			$model->values($values, $load_type === Mango::CLEAN);
		}

		return $model;
	}

	/**
	 * Finds extended model name based on values array
	 *
	 * @param   string   name of (base) model
	 * @param   array    data from database
	 * @return  string   name of (extended) model
	 */
	public static function extend($name, array $values)
	{
		if ( self::$_cti === NULL)
		{
			// load extension config
			self::$_cti = Kohana::$config->load('mangoCTI');
		}

		while ( isset(self::$_cti[$name]))
		{
			$key = key(self::$_cti[$name]);

			if ( isset($values[$key]) && isset(self::$_cti[$name][$key][$values[$key]]))
			{
				// extend
				$name = self::$_cti[$name][$key][$values[$key]];
			}
			else
			{
				break;
			}
		}

		return $name;
	}

	/**
	 * @var  string  model name
	 */
	protected $_model;

	/**
	 * @var  string  database instance name (defaults to MangoDB::$default)
	 */
	protected $_db = NULL;

	/**
	 * @var  string  database collection name
	 */
	protected $_collection;

	/**
	 * @var  boolean  indicates if model is embedded
	 */
	protected $_embedded = FALSE;

	/**
	 * @var  Mango   reference to parent object
	 */
	protected $_parent;

	/**
	 * @var  array  field list (name => field data)
	 */
	protected $_fields = array();

	/**
	 * @var  array  relation list (name => relation data)
	 */
	protected $_relations = array();

	/**
	 * @var  array  object data
	 */
	protected $_object = array();

	/**
	 * @var  array  clean object data (straight from DB)
	 */
	protected $_clean = array();

	/**
	 * @var  array  related data
	 */
	protected $_related = array();

	/**
	 * @var  array  changed fields
	 */
	protected $_changed = array();

	/**
	 * @var  boolean  initialization status
	 */
	protected $_init = FALSE;

	/**
	 * Calls the init() method. Mango constructors are only called once!
	 *
	 * @param   string   model name
	 * @return  void
	 */
	final protected function __construct()
	{
		$this->init();
	}

	/**
	 * Returns the model name.
	 *
	 * @return  string
	 */
	public function __toString()
	{
		return $this->_model;
	}

	/**
	 * Clones each of the fields and empty the model.
	 *
	 * @return  void
	 */
	public function __clone()
	{
		$this->clear();
	}

	/**
	 * Returns the attributes that should be serialized.
	 *
	 * @return  void
	 */
	public function __sleep()
	{
		return array('_object', '_clean', '_changed');
	}

	/**
	 * Restores model data
	 *
	 * @return  void
	 */
	public function __wakeup()
	{
		$this->init();
	}

	/**
	 * Checks if a field is set
	 *
	 * @return  boolean  field is set
	 */
	public function __isset($name)
	{
		return isset($this->_fields[$name]) 
			? (isset($this->_object[$name])  || isset($this->_clean[$name]))
			: isset($this->_related[$name]);
	}

	/**
	 * Empties the model
	 */
	public function clear()
	{
		$this->_object = $this->_clean = $this->_changed = array();

		return $this;
	}

	/**
	 * Return field data for a certain field
	 *
	 * @param   string         field name
	 * @return  boolean|array  field data if field exists, otherwise FALSE
	 */
	public function field($name)
	{
		return isset($this->_fields[$name])
			? $this->_fields[$name]
			: FALSE;
	}

	/**
	 * Return field data
	 *
	 * @return  array  field data
	 */
	public function fields()
	{
		return $this->_fields;
	}

	/**
	 * Return TRUE if field has been changed
	 *
	 * @param   string   field name
	 * @return  boolean  field has been changed
	 */
	public function is_changed($field)
	{
		return isset($this->_changed[$field]);
	}

	/**
	 * Return db reference
	 *
	 * @return  MangoDB  database object
	 */
	public function db()
	{
		if ( ! is_object($this->_db) )
		{
			// Initialize DB
			$this->_db = MangoDB::instance($this->_db);
		}

		return $this->_db;
	}

	/**
	 * Retrieve creation timestamp from MongoID object
	 *
	 * @return   int   Timestamp
	 */
	public function get_time()
	{
		if ( ! $this->loaded())
		{
			throw new Mango_Exception('Creation timestamp is only available on created documents');
		}

		if ( $this->_fields['_id']['type'] !== 'MongoId')
		{
			throw new Mango_Exception('Creation timestamp can only be deduced from MongoId document IDs, you\'re using: :id', array(
				':id' => $this->_fields['_id']['type']
			));
		}

		return $this->__get('_id')->getTimestamp();
	}

	/**
	 * Store a reference of the parent object (this is done by Mango internally)
	 *
	 * @param   Object   parent object
	 * @return  this
	 */
	public function set_parent( Mango & $parent)
	{
		if ( ! $this->_embedded)
		{
			throw new Mango_Exception('Only embedded documents have a parent document');
		}

		$this->_parent = $parent;

		return $this;
	}

	/**
	 * Return reference to parent object
	 *
	 * @return   Object   parent object
	 * @throws   only embedded objects have a parent object
	 */
	public function get_parent()
	{
		if ( ! $this->_embedded)
		{
			throw new Mango_Exception('Only embedded documents have a parent document');
		}

		return $this->_parent;
	}

	/**
	 * Get the value of a field.
	 *
	 * @throws  Mango_Exception  field does not exist
	 * @param   string  field name
	 * @return  mixed
	 */
	public function __get($name)
	{
		if ( ! $this->_init)
		{
			$this->init();
		}

		if ( isset($this->_fields[$name]))
		{
			$field = $this->_fields[$name];

			if ( isset($this->_clean[$name]))
			{
				// lazy load - field hasn't been loaded yet

				// move value from _clean to _object
				$this->_object[$name] = $this->load_field($name, $this->_clean[$name], TRUE);
				unset($this->_clean[$name]);
			}

			$value = isset($this->_object[$name])
				? $this->_object[$name]
				: NULL;

			switch ( $field['type'])
			{
				case 'enum':
					$value = isset($value) && isset($field['values'][$value]) 
						? $field['values'][$value] 
						: NULL;
				break;
				case 'set':
				case 'array':
				case 'has_one':
				case 'has_many':
					if ( $value === NULL)
					{
						// 'secretly' create value - access _object directly, not recorded as change
						$value = $this->_object[$name] = $this->load_field($name,array());
					}
				break;
				case 'counter':
					if ( $value === NULL)
					{
						// 'secretly' create counter - access _object directly, not recorded as change
						$value = $this->_object[$name] = $this->load_field($name,0);
					}
				break;
			}

			if ( $value === NULL && isset($field['default']))
			{
				$value = $field['default'];
			}

			return $value;
		}
		elseif ( isset($this->_relations[$name]))
		{
			if ( ! isset($this->_related[$name]))
			{
				$relation = $this->_relations[$name];

				switch ( $relation['type'])
				{
					case 'has_one':
						$criteria = array($this->_model . '_id' => $this->_id);
						$limit    = 1;
					break;
					case 'belongs_to':
						$id       = isset($this->_clean[$name . '_id']) ? $this->_clean[$name . '_id'] : $this->_object[$name . '_id'];
						$criteria = array('_id' => $id);
						$limit    = 1;
					break;
					case 'has_many':
						$criteria = array( ARR::get($relation, 'relation_name', $this->_model) . '_id' => $this->_id);
						$limit    = FALSE;
					break;
					case 'has_and_belongs_to_many':
						$criteria = array('_id' => array('$in' => $this->__get($name . '_ids')->as_array()));
						$limit    = FALSE;
					break;
				}

				$parameters = array(
					'limit'    => $limit,
					'criteria' => $criteria
				);

				$this->_related[$name] = Mango::factory($relation['model'])->load( $parameters );
			}

			return $this->_related[$name];
		}
		else
		{
			throw new Mango_Exception(':name model does not have a field :field',
				array(':name' => $this->_model, ':field' => $name));
		}
	}

	/**
	 * Set the value of a field.
	 *
	 * @throws  Mango_Exception  field does not exist
	 * @param   string  field name
	 * @param   mixed   new field value
	 * @return  mixed
	 */
	public function __set($name, $value)
	{
		if ( ! $this->_init)
		{
			$this->init();
		}

		if ( isset($this->_fields[$name]))
		{
			$match_default = isset($this->_fields[$name]['default'])
				? $value === $this->_fields[$name]['default']
				: FALSE;

			$value = $this->load_field($name, $value);

			if ( $this->__isset($name))
			{
				if ( $value === NULL || $match_default)
				{
					// setting existing field to NULL / default value -> unset value
					return $this->__unset($name);
				}

				// don't update value if the value did not change
				$same_clean  = isset($this->_clean[$name]) && $this->_clean[$name] === Mango::normalize($value);
				$same_object = ! $same_clean && isset($this->_object[$name]) && Mango::normalize($this->_object[$name]) === Mango::normalize($value);

				if ( $same_clean || $same_object)
				{
					return FALSE;
				}
			}
			elseif ( $value === NULL || $value === '' || $match_default)
			{
				// setting unset field to NULL / empty string / default value -> nothing happens
				return;
			}

			// update object
			$this->_object[$name] = $value;
			unset($this->_clean[$name]);

			// mark change
			$this->_changed[$name] = TRUE;
		}
		elseif ( isset($this->_relations[$name]) && in_array($this->_relations[$name]['type'], array('belongs_to', 'has_one')))
		{
			if ( $this->_relations[$name]['type'] === 'belongs_to')
			{
				$this->__set($name . '_id',$value->_id);
			}

			$this->_related[$name] = $value;
		}
		else
		{
			throw new Mango_Exception(':name model does not have a field :field',
				array(':name' => $this->_model, ':field' => $name));
		}
	}

	/**
	 * Unset a field
	 *
	 * @param   string  field name
	 * @return  void
	 */
	public function __unset($name)
	{
		if ( ! $this->_init)
		{
			$this->init();
		}

		if ( isset($this->_fields[$name]))
		{
			if ( $this->__isset($name))
			{
				// unset field
				unset($this->_object[$name], $this->_clean[$name]);

				// mark unset
				$this->_changed[$name] = FALSE;
			}
		}
		else
		{
			throw new Mango_Exception(':name model does not have a field :field',
				array(':name' => $this->_model, ':field' => $name));
		}
	}

	/**
	 * Initialize the fields and add validation rules based on field properties.
	 *
	 * @return  void
	 */
	protected function init()
	{
		if ( $this->_init)
		{
			// Can only be called once
			return;
		}

		// Set up fields
		$this->set_model_definition();

		if ( ! $this->_model)
		{
			// Set the model name based on the class name
			$this->_model = strtolower(substr(get_class($this), 6));
		}

		foreach ( $this->_fields as $name => & $field)
		{
			if ( $field['type'] === 'has_one' && ! isset($field['model']))
			{
				$field['model'] = $name;
			}
			elseif ( $field['type'] === 'has_many' && ! isset($field['model']))
			{
				$field['model'] = Inflector::singular($name);
			}
		}

		if (!$this->_embedded )
		{
			if ( ! $this->_collection)
			{
				// Set the collection name to the plural model name
				$this->_collection = Inflector::plural($this->_model);
			}

			if ( ! isset($this->_fields['_id']))
			{
				// default _id field
				$this->_fields['_id'] = array('type'=>'MongoId');
			}

			// Normalize relations
			foreach ( $this->_relations as $name => &$relation)
			{
				if ( $relation['type'] === 'has_and_belongs_to_many')
				{
					$relation['model'] = isset($relation['model']) 
						 ? $relation['model']
						 : Inflector::singular($name);

					$relation['related_relation'] = isset($relation['related_relation'])
						? $relation['related_relation']
						: Inflector::plural($this->_model);
				}
				elseif ( ! isset($relation['model']))
				{
					if ( $relation['type'] === 'belongs_to' || $relation['type'] === 'has_one')
					{
						$relation['model'] = $name;
					}
					else
					{
						$relation['model'] = Inflector::singular($name);
					}
				}

				switch ( $relation['type'])
				{
					case 'belongs_to':
						$this->_fields[$name . '_id'] = array('type'=>'MongoId', 'required' => ! Arr::get($relation, 'sparse', FALSE));
					break;
					case 'has_and_belongs_to_many':
						$this->_fields[$name . '_ids'] = array('type'=>'set', 'duplicates' => FALSE);
					break;
				}
			}
		}

		$this->_init = TRUE;
	}

	/**
	 * Overload in child classes to setup model definition
	 * Call $this->_set_model_definition( array( ... ));
	 *
	 * @return  void
	 */
	protected function set_model_definition() {}

	/**
	 * Add definition to this model.
	 * Called in child classes in the set_model_definition method
	 *
	 * @return  void
	 */
	protected function _set_model_definition(array $definition)
	{
		if ( isset($definition['_fields']))
		{
			$this->_fields = array_merge($this->_fields,$definition['_fields']);
		}

		if ( isset($definition['_relations']))
		{
			$this->_relations = array_merge($this->_relations,$definition['_relations']);
		}
	}

	/**
	 * Load all of the values in an associative array. Ignores all fields
	 * not in the model.
	 *
	 * @param   array    field => value pairs
	 * @param   boolean  values are clean (from database)?
	 * @return  $this
	 */
	public function values(array $values, $clean = FALSE)
	{
		if ( $clean)
		{
			// lazy loading - clean values are loaded when accessed
			$this->_clean = array_intersect_key($values, $this->_fields) + $this->_clean;
		}
		else
		{
			foreach ($values as $field => $value)
			{
				if ( isset($this->_fields[$field]) || ( isset($this->_relations[$field]) && $this->_relations[$field]['type'] === 'belongs_to'))
				{
					// Set the field using __set()
					$this->$field = $value;
				}
			}
		}

		return $this;
	}

	/**
	 * Get the model data as an associative array.
	 * @param  boolean  retrieve values directly from _object
	 *
	 * @return  array  field => value
	 */
	public function as_array( $clean = TRUE )
	{
		$array = array();

		foreach ( $this->_fields as $field_name => $field_data)
		{
			if ( $this->__isset($field_name))
			{
				if ( Arr::get($field_data,'local') === TRUE)
				{
					// local fields are not included in as_array array (not stored in db, not included in JSON objects)
					continue;
				}

				if ( $clean && isset($this->_clean[$field_name]))
				{
					// use 'clean' value
					$array[ $field_name ] = $this->_clean[$field_name];
				}
				else
				{
					// use 'loaded' value (that has to be normalized)
					$value = $clean
						? $this->_object[$field_name]
						: $this->__get($field_name);

					if ( ! $clean && Arr::get($field_data, 'xss_clean') && is_string($value))
					{
						// undo htmlspecialchars encoding done by HTML purifier
						$value = htmlspecialchars_decode($value);
					}

					$array[ $field_name ] = Mango::normalize( $value, $clean );
				}
			}
			else if ( ! $clean && isset($field_data['default']))
			{
				// use default value
				$array[ $field_name ] = $field_data['default'];
			}
		}

		return count($array) || ! $clean
			? $array
			: (object) array();
	}

	/**
	 * Test if the model is loaded.
	 *
	 * @return  boolean
	 */
	public function loaded()
	{
		return $this->_embedded || (isset($this->_id) && !isset($this->_changed['_id']));
	}

	/**
	 * Get all of the changed fields as an associative array.
	 *
	 * @param  boolean  indicate update (TRUE) or insert (FALSE) - (determines use of modifiers like $set/$inc)
	 * @param  array    prefix data, used internally
	 * @return  array  field => value
	 */
	public function changed($update, array $prefix= array())
	{
		$changed = array();

		foreach ( $this->_fields as $name => $field)
		{
			if (isset($field['local']) && $field['local'] === TRUE)
			{
				// local variables are not stored in DB
				continue;
			}

			$value = isset($this->_object[$name])
				? $this->_object[$name]
				: Arr::get($this->_clean, $name);

			// prepare prefix
			$path = array_merge($prefix,array($name));

			if ( isset($this->_changed[$name]))
			{
				// value has been changed
				if ( $value instanceof Mango_Interface)
				{
					$value = $value->as_array();
				}

				if ( $this->_changed[$name] === TRUE)
				{
					$data = array();
					$path = implode('.', $path);

					if ( $update)
					{
						$data = array('$set' => array($path => $value));
					}
					else
					{
						Arr::set_path($data, $path, $value);
					}

					$changed = Arr::merge($changed, $data);

					// __set
					/*$changed = $update
						? arr::merge($changed,array('$set'=>array( implode('.',$path) => $value) ) )
						: arr::merge($changed, arr::path_set($path,$value) );*/
				}
				else
				{
					// __unset
					if ( $update)
					{
						$changed = Arr::merge($changed, array('$unset'=> array( implode('.', $path) => TRUE)));
					}
				}
			}
			elseif ( $this->__isset($name))
			{
				// check any (embedded) objects/arrays/sets
				if ( $value instanceof Mango_Interface)
				{
					$changed = Arr::merge($changed, $value->changed($update,$path));
				}
			}
		}

		return $changed;
	}

	/**
	 * Indicate model has been saved, resets $this->_changed array
	 *
	 * @return  void
	 */
	public function saved()
	{
		foreach ( $this->_object as $value)
		{
			if ( $value instanceof Mango_Interface)
			{
				$value->saved();
			}
		}

		$this->_changed = array();
	}

	/**
	 * Reload model from database
	 *
	 * @return  $this
	 */
	public function reload()
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be reloaded from database',
				array(':name' => $this->_model));
		}
		elseif ( ! isset($this->_id))
		{
			throw new Mango_Exception(':name model cannot be reloaded, _id value missing',
				array(':name' => $this->_model));
		}

		$this->_changed = array(
			'_id' => TRUE
		);

		return $this->load();
	}

	/**
	 * Load a (set of) document(s) from the database
	 *
	 * Instead of listing all parameters individually, you can also supply a array, eg
	 * ->load( array( 'limit' => 1, 'criteria' => array(...));
	 * instead of
	 * ->load(1, NULL, NULL, array(), array(...));
	 *
	 * @param   mixed  limit the (maximum) number of models returned
	 * @param   array  sorts models on specified fields array( field => 1/-1 )
	 * @param   int    skip a number of results
	 * @param   array  specify the fields to return
	 * @param   array  specify additional criteria
	 * @return  mixed  if limit = 1, returns $this (or extended model), otherwise returns iterator
	 */
	public function load($limit = 1, array $sort = NULL, $skip = NULL, array $fields = array(), array $criteria = array())
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be loaded from database',
				array(':name' => $this->_model));
		}

		$criteria += $this->changed(FALSE);

		// parameters can also be supplied in an array (instead of each parameter individually)
		if ( is_array($limit))
		{
			// add default value for $limit, and extract values
			extract($limit + array(
				'limit'    => 1
			));
		}

		// resets $this->_changed array
		$this->clear();

		if ( $limit === 1 && $sort === NULL && $skip === NULL)
		{
			$values = $this->db()->find_one($this->_collection,$criteria,$fields);

			if ( $values === NULL)
			{
				return $this;
			}
			else
			{
				return $this->_model !== self::extend($this->_model, $values)
					? Mango::factory( self::extend($this->_model, $values), $values, Mango::CLEAN)
					: $this->values($values, TRUE);
			}
		}
		else
		{
			$values = $this->db()->find($this->_collection,$criteria,$fields);

			if ( is_int($limit))
			{
				$values->limit($limit);
			}

			if ( $sort !== NULL)
			{
				$values->sort($sort);
			}

			if ( is_int($skip))
			{
				$values->skip($skip);
			}

			return $limit === 1
				? ($values->hasNext()
						? $this->values( $values->getNext(), TRUE)
						: $this)
				: new Mango_Iterator($this->_model,$values);
		}
	}

	/**
	 * Create a new document using the current data.
	 *
	 * @param   array|boolean|integer   options array or value for 'safe' (true/false/replication integer)
	 *                                  see: http://www.php.net/manual/en/mongocollection.insert.php
	 * @return  $this
	 * @throws  Mango_Exception   Creating failed
	 */
	public function create($safe = TRUE)
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be created in the database',
				array(':name' => $this->_model));
		}

		if ( ! isset($this->_id))
		{
			// Generate MongoId
			$this->_id = new MongoId;
		}

		if ( $values = $this->changed(FALSE))
		{
			$options = is_array($safe)
				? $safe
				: array('safe' => $safe);

			try
			{
				// insert
				$this->db()->insert($this->_collection, $values, $options);
			}
			catch ( MongoCursorException $e)
			{
				throw new Mango_Exception('Unable to create :model, database returned error :error',
					array(':model' => $this->_model, ':error' => $e->getMessage()));
			}

			$this->saved();
		}

		return $this;
	}

	/**
	 * Update the current document using the current data.
	 *
	 * @param   array  Additional criteria for update
	 * @param   array|boolean|integer   options array or value for 'safe' (true/false/replication integer)
	 *                                  see: http://www.php.net/manual/en/mongocollection.insert.php
	 * @return  $this
	 * @throws  Mango_Exception   Updating failed
	 */
	public function update( $criteria = array(), $safe = TRUE)
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be updated itself (update parent instead)',
				array(':name' => $this->_model));
		}

		if ( $values = $this->changed(TRUE))
		{
			$criteria['_id'] = $this->_id;

			$options = is_array($safe) ? $safe : array('safe' => $safe);

			try
			{
				$this->db()->update($this->_collection, $criteria, $values, $options);
			}
			catch ( MongoCursorException $e)
			{
				throw new Mango_Exception('Unable to update :model, database returned error :error',
					array(':model' => $this->_model, ':error' => $e->getMessage()));
			}

			$this->saved();
		}
		
		return $this;
	}

	/**
	 * Delete the current document using the current data.
	 *
	 * @param   array|boolean|integer   options array or value for 'safe' (true/false/replication integer)
	 *                                  see: http://www.php.net/manual/en/mongocollection.remove.php
	 * @return  $this
	 */
	public function delete($safe = TRUE)
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be deleted (delete parent instead)',
				array(':name' => $this->_model));
		}
		elseif ( ! $this->loaded())
		{
			$this->load(1);

			if ( ! $this->loaded())
			{
				// model does not exist
				return $this;
			}
		}

		// Update/remove relations
		foreach ( $this->_relations as $name => $relation)
		{
			switch ( $relation['type'])
			{
				case 'has_one':
					$this->__get($name)->delete($safe);
				break;
				case 'has_many':
					foreach ( $this->__get($name) as $hm)
					{
						$hm->delete($safe);
					}
				break;
				case 'has_and_belongs_to_many':
					$set = $this->__get($name . '_ids')->as_array();

					if ( ! empty($set))
					{
						$this->db()->update( $name, array('_id' => array('$in' => $set)), array('$pull' => array($relation['related_relation'] . '_ids' => $this->_id)), array('multiple' => TRUE));
					}
				break;
			}
		}

		$options = is_array($safe) ? $safe : array('safe' => $safe);

		try
		{
			$this->db()->remove($this->_collection, array('_id'=> $this->_id), $options);
		}
		catch ( MongoCursorException $e)
		{
			throw new Mango_Exception('Unable to remove :model, database returned error :error',
				array(':model' => $this->_model, ':error' => $e->getMessage()));
		}

		return $this;
	}

	/**
	 * Run complex filtering rules on the object (and its embedded objects).
	 *
	 * Some models have complex filtering rules. For example when filtering of field1 depends
	 * on the value of existance of field2. In cases like these, you should overload
	 * Mango_Core::pre_filter in your model, and filter & validate your models like this
	 *
	 * $model
	 *    ->pre_filter()
	 *    ->check();
	 *
	 * @return  Mango    $this
	 */
	public function pre_filter()
	{
		// call pre_filter() on embedded objects
		foreach ( $this->_fields as $field_name => $field_data)
		{
			if ( $field_data['type'] === 'has_one' && $this->__isset($field_name))
			{
				$this->__get($field_name)->pre_filter();
			}
			else if ( $field_data['type'] === 'has_many' && $this->__isset($field_name))
			{
				foreach ( $this->__get($field_name) as $f)
				{
					$f->pre_filter();
				}
			}
		}

		return $this;
	}

	/**
	 * Validates data. If no data supplied, uses current data in document. Checks embedded_documents as well.
	 *
	 * @throws  Validation_Exception  when an error is found
	 * @param   subject  specify what part of $data should be subjected to validation, Mango::CHECK_FULL, Mango::CHECK_LOCAL, Mango::CHECK_ONLY
	 * @param   array    data to check, defaults to current document data (including embedded documents)
	 * @return  Mango    $this
	 */
	public function check(array $data = NULL, $subject = 0)
	{
		if ( $data !== NULL)
		{
			// create a new object with data, and validate
			Mango::factory($this->_model, $data)->check(NULL, $subject);

			return $this;
		}

		$local = array();

		foreach ( $this->_fields as $field_name => $field_data)
		{
			if ( ! in_array($field_data['type'], array('has_one','has_many')) || $this->__isset($field_name))
			{
				// Don't check if regular fields are set, to include default values
				// Do check if embedded objects are set, they don't have default values and we cannot instantiate extended models
				$local[$field_name] = Mango::normalize($this->__get($field_name));
			}
		}

		if ( $subject !== Mango::CHECK_ONLY || count($local))
		{
			// validate local data
			$array = Validation::factory($local)
				->bind(':model', $this);

			// add validation rules
			$array = $this->_check($array);

			if ( $subject === Mango::CHECK_ONLY)
			{
				foreach ( $this->_fields as $field_name => $field_data)
				{
					if ( ! $this->__isset($field_name))
					{
						// do not validate this field
						unset($array[$field_name]);
					}
				}
			}

			if ( ! $array->check())
			{
				throw new Mango_Validation_Exception($this->_model,$array);
			}
		}

		if ( $subject !== Mango::CHECK_LOCAL)
		{
			// validate embedded documents
			foreach ( $this->_fields as $field_name => $field_data)
			{
				if ( $this->__isset($field_name) && in_array($field_data['type'], array('has_one','has_many')))
				{
					if ( $field_data['type'] === 'has_one')
					{
						$this->__get($field_name)->check(NULL, $subject);
					}
					else
					{
						foreach ( $this->__get($field_name) as $seq => $hm)
						{
							try
							{
								$hm->check(NULL, $subject);
							}
							catch ( Mango_Validation_Exception $e)
							{
								// add sequence number of failed object to exception
								$e->seq = $seq;
								throw $e;
							}
						}
					}
				}
			}
		}

		return $this;
	}

	/**
	 * Add validation rules to validation object
	 *
	 * @param  Validation  Validation object
	 * @return array
	 */
	protected function _check(Validation $data)
	{
		foreach ($this->_fields as $name => $field)
		{
			if ( $field['type'] === 'email')
			{
				$data->rule($name, 'email');
			}

			if ( Arr::get($field,'required'))
			{
				$data->rule($name, 'required');
			}

			if ( Arr::get($field,'unique'))
			{
				$data->rule($name, array(':model','_is_unique'),array(':validation', $name));
			}

			foreach ( array('min_value','max_value','min_length','max_length') as $rule)
			{
				if ( Arr::get($field, $rule) !== NULL)
				{
					$data->rule($name, $rule, array(':value', $field[$rule]));
				}
			}

			if ( isset($field['rules']))
			{
				$data->rules($name, $field['rules']);
			}

			if ( isset($field['label']))
			{
				$data->label($name, $field['label']);
			}
		}

		return $data;
	}

	/**
	 * Formats a value into correct a valid value for the specific field
	 *
	 * @param   string  field name
	 * @param   mixed   field value
	 * @param   boolean is value clean (from Db)
	 * @return  mixed   formatted value
	 */
	protected function load_field($name, $value, $clean = FALSE)
	{
		// Load field data
		$field = $this->_fields[$name];

		if ( ! $clean)
		{
			// Apply filters
			$value = $this->run_filters($name, $value);

			// Empty string
			if ( is_string($value) && $value === '')
			{
				$value = NULL;
			}
		}

		if ( $value !== NULL || $clean === TRUE)
		{
			switch ( $field['type'])
			{
				case 'MongoId':
					if ( $value !== NULL AND ! $value instanceof MongoId)
					{
						$value = new MongoId($value);
					}
				break;
				case 'date':
					if ( ! $value instanceof MongoDate)
					{
						$value = new MongoDate( is_int($value) ? $value : strtotime($value));
					}
				break;
				case 'enum':
					if ( $clean)
					{
						$value = isset($field['values'][$value]) ? $value : NULL;
					}
					else
					{
						$value = ($key = array_search($value,$field['values'])) !== FALSE ? $key : NULL;
					}
				break;
				case 'int':
					if ((float) $value > PHP_INT_MAX)
					{
						// This number cannot be represented by a PHP integer, so we convert it to a float
						$value = (float) $value;
					}
					else
					{
						$value = (int) $value;
					}
				break;
				case 'float':
					$value = (float) $value;
				break;
				case 'timestamp':
					if ( ! is_int($value))
					{
						$value = ctype_digit($value)
							? (int) $value
							: strtotime($value);
					}
				break;
				case 'boolean':
					$value = (bool) $value;
				break;
				case 'email':
					if ( ! $clean)
					{
						$value = strtolower(trim((string) $value));
					}
				break;
				case 'string':
					$value = trim((string) $value);

					if ( ! $clean && strlen($value))
					{
						$max_size = Arr::get($field, 'max_size', Mango::MAX_SIZE_STRING);

						if ( UTF8::strlen($value) > $max_size)
						{
							$value = UTF8::substr($value, 0, $max_size);
						}

						if ( Arr::get($field, 'xss_clean') && ! empty($value))
						{
							$value = Security::xss_clean($value);
						}
					}
				break;
				case 'has_one':
					if ( is_array($value))
					{
						$value = Mango::factory($field['model'], $value, $clean ? Mango::CLEAN : Mango::CHANGE);
					}

					if ( ! $value instanceof Mango)
					{
						$value = NULL;
					}
					else
					{
						$value->set_parent($this);
					}
				break;
				case 'has_many':
					$value = new Mango_Set($value, $field['model'], Arr::get($field, 'duplicates', FALSE), $clean);

					foreach ( $value as $model)
					{
						$model->set_parent($this);
					}
				break;
				case 'counter':
					$value = new Mango_Counter($value);
				break;
				case 'array':
					$value = new Mango_Array($value, Arr::get($field, 'type_hint'), $clean);
				break;
				case 'set':
					$value = new Mango_Set($value, Arr::get($field, 'type_hint'), Arr::get($field, 'duplicates', TRUE), $clean);
				break;
				case 'mixed':
					$value = ! is_object($value)
						? $value
						: NULL;
				break;
			}

			if ( ! $clean && is_string($value) && $value === '')
			{
				$value = NULL;
			}
		}

		return $value;
	}

	/**
	 * Filters a value for a specific column
	 *
	 * @param  string $field  The column name
	 * @param  string $value  The value to filter
	 * @return string
	 *
	 * @package    Kohana/ORM
	 * @author     Kohana Team
	 * @copyright  (c) 2007-2010 Kohana Team
	 * @license    http://kohanaframework.org/license
	 */
	protected function run_filters($name, $value)
	{
		if ( ! isset($this->_fields[$name]['filters']))
		{
			return $value;
		}

		// Bind the field name and model so they can be used in the filter method
		$_bound = array
		(
			':field' => $name,
			':model' => $this,
		);

		foreach ($this->_fields[$name]['filters'] as $array)
		{
			// Value needs to be bound inside the loop so we are always using the
			// version that was modified by the filters that already ran
			$_bound[':value'] = $value;

			// Filters are defined as array($filter, $params)
			$filter = $array[0];
			$params = Arr::get($array, 1, array(':value'));

			foreach ($params as $key => $param)
			{
				if (is_string($param) AND array_key_exists($param, $_bound))
				{
					// Replace with bound value
					$params[$key] = $_bound[$param];
				}
			}

			if (is_array($filter))
			{
				// callback

				// Allows filters: array(':model', 'some_rule');
				if (is_string($filter[0]) AND array_key_exists($filter[0], $_bound))
				{
					// Replace with bound value
					$filter[0] = $_bound[$filter[0]];
				}

				$value = call_user_func_array($filter, $params);
			}
			elseif ( ! is_string($filter))
			{
				// This is a lambda
				$value = call_user_func_array($filter, $params);
			}
			elseif (strpos($filter, '::') === FALSE)
			{
				// Use a function call
				$function = new ReflectionFunction($filter);

				// Call $function($this[$field], $param, ...) with Reflection
				$value = $function->invokeArgs($params);
			}
			else
			{
				// Split the class and method of the rule
				list($class, $method) = explode('::', $filter, 2);

				// Use a static method call
				$method = new ReflectionMethod($class, $method);

				// Call $Class::$method($this[$field], $param, ...) with Reflection
				$value = $method->invokeArgs(NULL, $params);
			}
		}

		return $value;
	}

	/**
	 * Checks if model is related to supplied model
	 *
	 * @param   Mango    Model to check
	 * @param   string   Alternative name of model (optional)
	 * @return  boolean  Model is related
	 */
	public function has(Mango $model, $name = NULL)
	{
		if ( $name === NULL)
		{
			$name = (string) $model;
		}

		return $this->has_in_relation($model, Inflector::plural($name));
	}

	/**
	 * Shortcut to add a model to a relation (HABTM or embedded has_many)
	 * See Mango::add_relation
	 *
	 * @param   Mango    Model to add
	 * @param   string   Alternative name of model (optional)
	 * @return  boolean  Model was added
	 * @throws  No such relation exists
	 */
	public function add(Mango $model, $name = NULL)
	{
		if ( $name === NULL)
		{
			$name = (string) $model;
		}

		return $this->add_to_relation($model, Inflector::plural($name));
	}

	/**
	 * Shortcut to remove a model from a relation (HABTM or embedded has_many)
	 * See Mango::remove_relation
	 *
	 * @param   Mango    Model to remove
	 * @param   string   Alternative name of model (optional)
	 * @return  boolean  Model was removed
	 * @throws  No such relation exists
	 */
	public function remove(Mango $model, $name = NULL)
	{
		if ( $name === NULL)
		{
			$name = (string) $model;
		}

		return $this->remove_from_relation($model, Inflector::plural($name));
	}

	/**
	 * Checks if model is related to supplied model
	 *
	 * @param   Mango    Model to check
	 * @param   string   Alternative name of relation (optional)
	 * @return  boolean  Model is related
	 */
	public function has_in_relation(Mango $model, $relation)
	{
		if ( isset($this->_relations[$relation]) && $this->_relations[$relation]['type'] === 'has_and_belongs_to_many')
		{
			// related HABTM 
			$field = $relation . '_ids';
			$value = $model->_id;
		}
		elseif ( isset($this->_fields[$relation]) && $this->_fields[$relation]['type'] === 'has_many' )
		{
			// embedded Has Many
			$field = $relation;
			$value = $model;
		}
		else
		{
			throw new Mango_Exception('model :model has no relation with model :related',
				array(':model' => $this->_model, ':related' => $relation));
		}

		return isset($field) 
			? ($this->__isset($field) ? $this->__get($field)->find($value) !== FALSE : FALSE) 
			: FALSE;
	}

	/**
	 * Adds model to relation
	 *
	 * @param   Mango    Model to add
	 * @param   string   Alternative name of relation (optional)
	 * @return  boolean  Model was added
	 * @return  boolean  Model was added to related model as well (used internally for HABTM)
	 * @throws  No such relation exists
	 */
	public function add_to_relation(Mango $model, $relation, $returned = FALSE)
	{
		if ( $this->has_in_relation($model,$relation))
		{
			// already added
			return TRUE;
		}

		if ( isset($this->_relations[$relation]) && $this->_relations[$relation]['type'] === 'has_and_belongs_to_many')
		{
			// related HABTM
			if ( ! $model->loaded() || ! $this->loaded() )
			{
				return FALSE;
			}

			$field = $relation . '_ids';

			// try to push
			if ( $this->__get($field)->push($model->_id))
			{
				// push succeed

				// column has to be reloaded
				unset($this->_related[$relation]);

				if ( ! $returned )
				{
					// add relation to model as well
					$model->add_to_relation($this,$this->_relations[$relation]['related_relation'],TRUE);
				}
			}

			// model has been added or was already added
			return TRUE;
		}
		elseif ( isset($this->_fields[$relation]) && $this->_fields[$relation]['type'] === 'has_many' )
		{
			return $this->__get($relation)->push($model);
		}
		else
		{
			throw new Mango_Exception('there is no :relation specified', array(':relation' => $relation));
		}

		return FALSE;
	}

	/**
	 * Removes model to relation
	 *
	 * @param   Mango    Model to remove
	 * @param   string   Alternative name of relation (optional)
	 * @return  boolean  Model was removed
	 * @return  boolean  Model was removed from related model as well (used internally for HABTM)
	 * @throws  No such relation exists
	 */
	public function remove_from_relation(Mango $model, $relation, $returned = FALSE)
	{
		if ( ! $this->has_in_relation($model,$relation))
		{
			// already removed
			return TRUE;
		}

		if ( isset($this->_relations[$relation]) && $this->_relations[$relation]['type'] === 'has_and_belongs_to_many')
		{
			// related HABTM
			if ( ! $model->loaded() || ! $this->loaded())
			{
				return FALSE;
			}

			$field = $relation . '_ids';

			// try to pull
			if ( $this->__get($field)->pull($model->_id))
			{
				// pull succeed

				// column has to be reloaded
				unset($this->_related[$relation]);

				if ( ! $returned )
				{
					// remove relation from related model as well
					$model->remove_from_relation($this,$this->_relations[$relation]['related_relation'],TRUE);
				}
			}

			// model has been removed or was already removed
			return TRUE;
		}
		elseif ( isset($this->_fields[$relation]) && $this->_fields[$relation]['type'] === 'has_many' )
		{
			// embedded Has_Many
			return $this->__get($relation)->pull($model);
		}
		else
		{
			throw new Mango_Exception('there is no :relation specified', array(':relation' => $relation));
		}

		return FALSE;
	}

	/**
	 * Validation rule
	 *
	 * Verifies if field is unique
	 */
	public function _is_unique(Validation $array, $field)
	{
		if ( $this->loaded() AND ! $this->is_changed($field))
		{
			// This value is unchanged
			return TRUE;
		}

		return $this->db()->find_one( $this->_collection, array($field => $array[$field]), array('_id'=>TRUE)) === NULL;
	}

	/**
	 * Normalize value to default format so values can be compared
	 *
	 * (comparing two identical objects in PHP will return FALSE)
	 *
	 * @param   mixed   value to normalize
	 * @param   boolean whether to clean data (see Mango::as_array)
	 * @return  mixed   normalized value
	 */
	public static function normalize($value, $clean = FALSE)
	{
		if ( $value instanceof Mango_Interface)
		{
			return $value->as_array( $clean );
		}
		elseif ( $value instanceof MongoId)
		{
			return $clean
				? $value
				: (string) $value;
		}
		else
		{
			return $value;
		}
	}
}