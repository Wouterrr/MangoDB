<?php

/*
 * Mango implementation of a Mongo Javascript Object.
 *
 * Can also act as counter in a multidimensional array of counters
 * (see MangoDemo - demo8)
 */

class Kohana_Mango_Array extends Mango_ArrayObject {

	/**
	 * @var   Mango_Counter object in case array is acting as counter
	 */
	protected $_counter;

	/*
	 * Return array of changes
	 */
	public function changed($update, array $prefix = array())
	{
		if ( isset($this->_counter))
		{
			// acting as counter
			return $this->_counter->changed($update, $prefix);
		}

		$changed = array();

		// Get a list of all relevant keys (current + unset keys)

		$keys = array();
		foreach($this as $key => $value)
		{
			$keys[] = $key;
		}
		// don't use array_unique(array_merge(array_keys($this->_changed),$keys)) - array_unique has issues with 0 values (eg array_unique( array('a',0,0,'a','c'));
		$keys = array_merge( $keys, array_diff( array_keys($this->_changed) ,$keys) );

		// Walk through array
		
		foreach($keys as $key)
		{
			$value = $this->offsetExists($key) 
				? $this->offsetGet($key) 
				: NULL;

			if ( isset($this->_changed[$key]))
			{
				// value has been changed
				if($value instanceof Mango_Interface)
				{
					$value = $value->as_array();
				}

				$path = array_merge($prefix,array($key));

				if ( $this->_changed[$key] === TRUE)
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
						? arr::merge($changed, array( '$set' => array( implode('.',$path) => $value)))
						: arr::merge($changed, arr::path_set($path,$value) );*/
				}
				else
				{
					// __unset
					if ( $update)
					{
						$changed = Arr::merge($changed, array( '$unset' => array( implode('.',$path) => TRUE)));
					}
				}
			}
			elseif ($value instanceof Mango_Interface)
			{
				$changed = Arr::merge($changed, $value->changed($update, array_merge($prefix,array($key))));
			}
		}

		return $changed;
	}

	public function offsetGet($key)
	{
		if ( isset($this->_counter))
		{
			throw new Mango_Exception('This Mango_Array is acting as a counter');
		}

		return parent::offsetGet($key);
	}

	/*
	 * Set a key to value
	 *
	 * @param   string   key
	 * @param   mixed    value
	 * @return  void
	 */
	public function offsetSet($key, $newval)
	{
		if ( isset($this->_counter))
		{
			throw new Mango_Exception('This Mango_Array is acting as a counter');
		}

		if ( ($key = parent::offsetSet($key,$newval)) !== FALSE)
		{
			// new value - remember change
			$this->_changed[$key] = TRUE;
		}
	}

	/*
	 * Unset a key
	 *
	 * @param   string   key
	 * @return  void
	 */
	public function offsetUnset($key)
	{
		if ( isset($this->_counter))
		{
			throw new Mango_Exception('This Mango_Array is acting as a counter');
		}

		if ( $this->offsetExists($key))
		{
			parent::offsetUnset($key);

			$this->_changed[$key] = FALSE;
		}
	}

	/*
	 * Updated as_array method
	 *
	 * (empty Mango_Arrays (equivalent of JS objects) are converted to object to ensure they're saved correctly)
	 */
	public function as_array( $clean = TRUE )
	{
		if ( isset($this->_counter))
		{
			return $this->_counter->as_array($clean);
		}

		$array = parent::as_array( $clean );

		return $clean && ! count($array) 
			? (object) array() 
			: $array;
	}

	/**
	 * Have Mango_Array act as counter - increment
	 *
	 * @param  int   value to increment counter by
	 * @return void
	 */
	public function increment($value = 1)
	{
		$this->act_as_counter($value);
	}

	/**
	 * Have Mango_Array act as counter - decrement
	 *
	 * @param  int   value to decrement counter by
	 * @return void
	 */
	public function decrement($value = 1)
	{
		$this->act_as_counter( -1 * $value);
	}

	/**
	 * Act as counter method, available to support multi dimensional arrays with counters
	 * structure of (multi dimensional) array doesn't have to be set beforehand.
	 */
	protected function act_as_counter($value)
	{
		// verify if array can act as counter
		if ( $this->_type_hint !== 'counter' || count($this) > 0 || $this->_changed !== array())
		{
			throw new Mango_Exception('This Mango_Array cannot act as counter');
		}

		if ( ! isset($this->_counter))
		{
			// create counter
			$this->_counter = new Mango_Counter;
		}

		$this->_counter->increment($value);
	}
}