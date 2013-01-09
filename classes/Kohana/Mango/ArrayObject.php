<?php

/**
 * ArrayObject base class used by both Mango_Set (corresponds to Javascript array) and Mango_Array (corresponds to Javascript object)
 */

class Kohana_Mango_ArrayObject extends ArrayObject implements Mango_Interface {

	/**
	 * Remembers changes made to this array object (for updating)
	 */
	protected $_changed = array();

	/**
	 * Stores the type of value stored in this array object
	 */
	protected $_type_hint;

	/**
	 * Constructor
	 *
	 * @param   array   Current data
	 * @param   string  Type Hint
	 * @param   boolean Is data clean (from DB?)
	 * @return  void
	 */
	public function __construct($array = array(), $type_hint = NULL, $clean = FALSE)
	{
		// Make sure we're dealing with an array
		if ( $array instanceof Mango_ArrayObject)
		{
			$array = $array->as_array(FALSE);
		}
		elseif ( ! is_array($array))
		{
			$array = array();
		}

		// create
		parent::__construct($array,ArrayObject::STD_PROP_LIST);

		if ( $type_hint !== NULL)
		{
			// set typehint
			$this->_type_hint = strtolower($type_hint);

			// load to make sure values are of correct type
			$this->load($clean);
		}
	}

	/**
	 * Returns an array with changes - implemented by child classes
	 */
	public function changed($update, array $prefix = array()) {}

	/**
	 * Ensures all values are of correct type
	 *
	 * @param   boolean Is data clean (from DB?)
	 */
	public function load($clean)
	{
		foreach( $this as &$value)
		{
			// replace by value loaded to correct type
			$value = $this->load_type($value, $clean);
		}
	}

	/**
	 * Loads a value into correct type
   *
	 * @param   boolean Is data clean (from DB?)
	 */
	public function load_type($value, $clean = FALSE)
	{
		switch ( $this->_type_hint)
		{
			case NULL:
				// do nothing
			break;
			case 'counter':
				$value = is_array($value)
					? new Mango_Array($value, $this->_type_hint, $clean) // multidimensional array of counters
					: new Mango_Counter($value);
			break;
			case 'set':
				if ( is_array($value))
				{
					$value = new Mango_Set($value, $this->_type_hint, FALSE, $clean);
				}
			break;
			case 'array':
				if ( is_array($value))
				{
					$value = new Mango_Array($value, $this->_type_hint, $clean);
				}
			break;
			default:
				$value = is_object($value) 
					? $value
					: Mango::factory($this->_type_hint, $value, $clean ? Mango::CLEAN : Mango::CHANGE);
			break;
		}

		return $value;
	}

	/**
	 * Returns object as array
	 */
	public function getArrayCopy()
	{
		return $this->as_array();
	}

	/**
	 * Returns object as array
	 *
	 * @param   boolean  fetch value directly from object
	 * @return  array    array representation of array object
	 */
	public function as_array( $clean = TRUE )
	{
		$array = parent::getArrayCopy();

		foreach ( $array as &$value)
		{
			if ( $value instanceof Mango_Interface)
			{
				$value = $value->as_array( $clean );
			}
		}

		return $array;
	}

	/**
	 * Set status to saved
	 */
	public function saved()
	{
		$this->_changed = array();

		foreach ( $this as $value)
		{
			if ( $value instanceof Mango_Interface)
			{
				$value->saved();
			}
		}
	}

	/**
	 * Fetch value
	 */
	public function offsetGet($index)
	{
		if ( ! $this->offsetExists($index) )
		{
			// implicit set ($array[1][2] = 3)
			switch($this->_type_hint)
			{
				case 'array':
				case 'set':
				case 'counter': 
					// counter also defaults to array, to support multidimensional counters
					// (Mango_Array can act as a counter itself aswell, so leaving all options available)
					$value = array();
				break;
				case NULL:
					// implicit set is only possible when we know the array type.
					throw new Mango_Exception('Set typehint to \'set\', \'array\', \'counter\' or model name (now: :typehint) to support implicit array creation', 
						array(':typehint' => $this->_type_hint ? '\''.$this->_type_hint.'\'' : 'not set'));
				break;
				default:
					$value = Mango::factory($this->_type_hint);
				break;
			}

			// secretly set value (via parent::offsetSet, so no change is recorded)
			parent::offsetSet($index,$this->load_type($value));
		}

		return parent::offsetGet($index);
	}

	/**
	 * Set key to value
	 */
	public function offsetSet($index,$newval)
	{
		// make sure type is correct
		$newval = $this->load_type($newval);

		if ( $index !== NULL && $this->offsetExists($index))
		{
			$current = $this->offsetGet($index);

			// only update if new data
			if ( Mango::normalize($current) === Mango::normalize($newval))
			{
				return FALSE;
			}
		}

		// set
		parent::offsetSet($index,$newval);

		// on $array[], the $index newval === NULL
		if ( $index === NULL)
		{
			// find index of last occurence of $newval
			$index = $this->find($newval, -1);
		}

		return $index;
	}

	/**
	 * Find index of n'th occurence of value in array
	 *
	 * @param   mixed         Needle
	 * @param   int           n'th occurence (negative = count from end)
	 * @return  int|boolean   index of n'th occurence of needle, or FALSE
	 */
	public function find($needle, $n = 0)
	{
		// normalize needle
		$needle   = Mango::normalize($needle);

		// create normalized haystack
		$haystack = array();
		foreach ( $this as $key => $val)
		{
			$haystack[$key] = Mango::normalize($val);
		}

		// perform search
		$keys = array_keys($haystack, $needle);

		if ( $n < 0 )
		{
			// reverse array and $n
			$keys = array_reverse($keys);
			$n = $n * -1 - 1;
		}

		return isset($keys[$n]) ? $keys[$n] : FALSE;
	}

	/**
	 * Create an (associative) array of values from this array object
	 *
	 * $blog->comments->select_list('id','author');
	 * $blog->comments->select_list('author');
	 *
	 * @param   string   key1
	 * @param   string   key2 (optional)
	 * @return  array    key1 => key2 or key1,key1,key1
	 */
	public function select_list($key = 'id',$val = NULL)
	{
		if ( $val === NULL)
		{
			$val = $key;
			$key = NULL;
		}

		$list = array();

		foreach ( $this as $object)
		{
			if ( $key !== NULL)
			{
				$list[$object->$key] = $object->$val;
			}
			else
			{
				$list[] = $object->$val;
			}
		}

		return $list;
	}

	/**
	 * Push a value onto array, similar to $array[]
	 */
	public function push($newval)
	{
		return $this->offsetSet(NULL,$newval);
	}

	/**
	 * Pull a value from array
	 */
	public function pull($oldval)
	{
		if ( ($index = $this->find($this->load_type($oldval))) !== FALSE )
		{
			$this->offsetUnset( $index );
		}

		return TRUE;
	}


	/**
	 * Find a path in array
	 *
	 * @param   string|array  delimiter notated keystring or array
	 * @param   mixed         default value to return if key not found
	 * @param   string        delimiter (defaults to dot '.')
	 * @return  mixed         value (if found) or default value
	 */
	public function path_get($path, $default = NULL, $delimiter = '.')
	{
		if ( ! is_array($path))
		{
			$path = explode($delimiter,(string) $path);
		}

		$next = $this;

		while ( count($path) && (is_array($next) || $next instanceof ArrayObject))
		{
			$key = array_shift($path);

			$next = isset($next[$key])
				? $next[$key]
				: $default;
		}

		return ! count($path)
			? Mango::normalize($next)
			: $default;
	}

	/**
	 * Set path to value
	 *
	 * @param   string|array  delimiter notated keystring or array
	 * @param   mixed         value to store
	 * @param   string        delimiter (defaults to dot '.')
	 * @return  void
	 */
	public function path_set($path, $value, $delimiter = '.')
	{
		if ( $this->_type_hint !== 'set' && $this->_type_hint !== 'array')
		{
			throw new Mango_Exception('Recursive loading of path only possible when type hint is set');
		}

		if ( ! is_array($path))
		{
			// Split the keys by dots
			$path = explode($delimiter, trim($path, $delimiter));
		}

		$next = $this;

		while ( count($path) > 1)
		{
			$next = $next[ array_shift($path) ];
		}

		$next[ array_shift($path) ] = $value;
	}

	/**
	 * Unsets path
	 *
	 * @param   string|array  delimiter notated keystring or array
	 * @param   string        delimiter (defaults to dot '.')
	 * @return  void
	 */
	public function path_unset($path, $delimiter = '.')
	{
		if ( $this->_type_hint !== 'set' && $this->_type_hint !== 'array')
		{
			throw new Mango_Exception('Recursive loading of path only possible when type hint is set');
		}

		if ( ! is_array($path))
		{
			// Split the keys by dots
			$path = explode($delimiter, trim($path, $delimiter));
		}

		// separate arrays for keys and references because array_reverse changes numerical keys
		$refs = array();
		$keys = array();

		$next = $this;

		$last = end($path);

		foreach ( $path as $key => $value)
		{
			if ( ! isset($next[$value]))
			{
				break;
			}

			$keys[] = $value;
			$refs[] = $next;

			$next = $next[$value];
		}

		// reverse arrays
		$keys = array_reverse($keys);
		$refs = array_reverse($refs);

		foreach ( $keys as $seq => $key)
		{
			$field = $refs[$seq];

			if ( $key === $last || ($field[$key] instanceof ArrayObject && count($field[$key]) === 0))
			{
				unset($field[$key]);
			}
		}
	}
}