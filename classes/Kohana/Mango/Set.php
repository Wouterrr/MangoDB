<?php

/*
 * Mango implementation of a Mongo Javascript Array.
 */

class Kohana_Mango_Set extends Mango_ArrayObject {

	/*
	 * MongoDB does not support using different modifiers at the same time on a single set,
	 * therefore we remember the current mode
	 */
	protected $_mode;

	/**
	 * Are duplicates allowed
	 */
	protected $_duplicates;

	/*
	 * Constructor
	 *
	 * @param   array    Current data
	 * @param   string   Type Hint
	 * @param   boolean  Are duplicates allowed
	 * @param   boolean  Is data clean (from DB?)
	 * @return  void
	 */
	public function __construct($array = array(), $type_hint = NULL, $duplicates = TRUE, $clean = FALSE)
	{
		$this->_duplicates = $duplicates;

		if ( ! $clean)
		{
			// Make sure we're dealing with an array
			if ( $array instanceof Mango_ArrayObject)
			{
				$array = $array->as_array(FALSE);
			}

			// Make sure we're dealing with non-associative arrays
			$array = array_values($array);

			if ( ! $this->_duplicates && isset($array))
			{
				$unique = array();

				foreach ( $array as $value)
				{
					if ( ! in_array($value, $unique, TRUE))
					{
						$unique[] = $value;
					}
				}

				// Only load unique values
				$array = $unique;
			}
		}

		parent::__construct($array, $type_hint, $clean);
	}

	/*
	 * Set status to saved
	 */
	public function saved()
	{
		$this->_mode = NULL;

		parent::saved();
	}

	/*
	 * Return array of changes
	 *
	 * @param   boolean   Are we updating or creating
	 * @param   array     Location of set in parent element
	 * @return  array     Update data
	 * @throws  Mango_Exception   within a single set, if already $push/$pull, then no other mods are possible
	 */
	public function changed($update, array $prefix = array())
	{
		// fetch changed elements in this set
		$elements = array();

		switch ( $this->_mode)
		{
			case 'push':
			case 'set':
			case 'addToSet':
				foreach ( $this->_changed as $index)
				{
					$elements[] = $this->offsetGet($index);
				}
			break;
			case 'pull':
				// changed values were stored in _changed array
				$elements = $this->_changed;
			break;
		}

		// normalize changed elements 
		foreach ( $elements as &$element)
		{
			if ( $element instanceof Mango_Interface)
			{
				$element = $element->as_array();
			}
		}

		if ( $update === FALSE)
		{
			// no more changes possible after this
			$data = array();

			if ( count($elements))
			{
				Arr::set_path($data, implode('.',$prefix), $elements);
			}

			return $data;

			/*return count($elements)
				? arr::path_set($prefix,$elements)
				: array();*/
		}

		// First, get all changes made to the elements of this set directly
		$changes_local = array();

		switch ( $this->_mode)
		{
			case 'pop':
				$changes_local = array('$pop' => array(implode('.',$prefix) => $this->_changed));
			break;
			case 'set':
				foreach ( $this->_changed as $index => $set_index)
				{
					$changes_local = Arr::merge($changes_local, array('$set' => array( implode('.',$prefix) . '.' . $set_index => $elements[$index])));
				}
			break;
			case 'unset':
				foreach ( $this->_changed as $unset_index)
				{
					$changes_local = Arr::merge($changes_local, array('$unset' => array( implode('.',$prefix) . '.' . $unset_index => TRUE)));
				}
			break;
			case 'addToSet':
				$elements = count($this->_changed) > 1
					? array('$each' => $elements)
					: $elements[0];

				$changes_local = array('$addToSet' => array(implode('.',$prefix) => $elements));
			break;
			case 'push':
			case 'pull':
				$mod = '$' . $this->_mode;

				if ( count($this->_changed) > 1)
				{
					$mod .= 'All';
				}
				else
				{
					$elements = $elements[0];
				}

				$changes_local = array($mod => array(implode('.',$prefix) => $elements));
			break;
		}

		// Second, get all changes made within children elements themselves
		$changes_children = array();

		foreach ( $this as $index => $value)
		{
			if ( ! is_array($this->_changed) || ! in_array($this->_mode, array('push','set','addToSet')) || ! in_array($index, $this->_changed))
			{
				if ( $value instanceof Mango_Interface)
				{
					$changes_children = Arr::merge($changes_children, $value->changed($update, array_merge($prefix,array($index))));
				}
			}
		}

		// Some modifiers don't work well together in Mongo - check for mistakes
		if ( isset($this->_mode) && $this->_mode !== 'set' && $this->_mode !== 'unset' && ! empty($changes_children))
		{
			throw new Mango_Exception('MongoDB does not support any other updates when already in :mode mode', array(
				':mode' => $this->_mode
			));
		}

		// Return all changes
		return Arr::merge( $changes_local, $changes_children);
	}

	/*
	 * Set value at index $index to $value
	 *
	 * @param   integer   index
	 * @param   mixed     value
	 * @return  void
	 * @throws  Mango_Exception   invalid key/action
	 */
	public function offsetSet($index,$newval)
	{
		// sets don't have associative keys
		if ( ! is_int($index) && ! is_null($index))
		{
			throw new Mango_Exception('Mango_Sets only supports numerical keys');
		}

		$mode = is_int($index) && $this->offsetExists($index)
			? 'set'
			: ($this->_duplicates ? 'push' : 'addToSet');

		if ( isset($this->_mode) && $this->_mode !== $mode)
		{
			throw new Mango_Exception('MongoDB cannot :action when already in :mode mode', array(
				':action' => $mode,
				':mode'   => $this->_mode
			));
		}

		if ( ! $this->_duplicates && $this->find($this->load_type($newval)) !== FALSE)
		{
			// value has been added already
			return TRUE;
		}

		// Set value
		$index = parent::offsetSet($index,$newval);

		if ( is_int($index))
		{
			// value was added successfully, set mode & index of changed value
			$this->_mode = $mode;
			$this->_changed[] = $index;
		}

		return TRUE;
	}

	/*
	 * Unset value at index $index
	 *
	 * @param   integer   index
	 * @return  void
	 * @throws  Mango_Exception   invalid key/action
	 */
	public function offsetUnset($index)
	{
		if ( ! ctype_digit((string)$index) && ! is_null($index))
		{
			throw new Mango_Exception('Mango_Sets only supports numerical keys');
		}

		if ( ! $this->offsetExists($index))
		{
			return;
		}

		$mode = $this->_duplicates ? 'unset' : 'pull';

		if ( isset($this->_mode) && $this->_mode !== $mode)
		{
			throw new Mango_Exception('MongoDB cannot :new_mode when already in :mode mode', array(
				':new_mode' => $mode,
				':mode'     => $this->_mode
			));
		}

		// set mode & pulled value
		$this->_mode = $mode;
		$this->_changed[] = $this->_duplicates
			? $index
			: $this->offsetGet($index);

		parent::offsetUnset($index);
	}

	/*
	 * Pop last value from array and return value
	 */
	public function pop()
	{
		return $this->_pop(true);
	}

	/*
	 * Shift first value from array and return value
	 */
	public function shift()
	{
		return $this->_pop(false);
	}

	protected function _pop($pop)
	{
		if ( isset($this->_mode))
		{
			throw new Mango_Exception('MongoDB cannot pop when already in :mode mode', array(
				':mode'     => $this->_mode
			));
		}

		if ( count($this) === 0)
		{
			// nothing to pop/shift
			return NULL;
		}

		$this->_mode = 'pop';
		$this->_changed = $pop ? 1 : -1;

		$offset = $pop ? count($this) - 1 : 0;
		$value  = $this->offsetGet($offset);

		parent::offsetUnset($offset);

		return $value;
	}

	/*
	 * Returns object as array
	 *
	 * @param   boolean  fetch value directly from object
	 * @return  array    array representation of array object
	 */
	public function as_array( $clean = TRUE )
	{
		// ensures a sequential array is returned
		return array_values(parent::as_array($clean));
	}
}