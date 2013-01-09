<?php defined('SYSPATH') OR die('No direct access allowed.');

class Kohana_Mango_Iterator implements Iterator, Countable {

	// Class attributes
	protected $_model;

	// MongoCursor object
	protected $_cursor;

	public function __construct($model, MongoCursor $cursor)
	{
		$this->_model = $model;
		$this->_cursor = $cursor;
	}

	public function cursor()
	{
		return $this->_cursor;
	}

	public function as_array( $objects = TRUE )
	{
		$array = array();

		foreach ( $this as $document)
		{
			$array[] = $objects 
				? $document
				: $document->as_array( FALSE );
		}

		return $array;
	}

	/*
	 * Create an (associative) array of values from this iterator
	 *
	 * $blog->comments->select_list('id','author');
	 * $blog->comments->select_list('author');
	 *
	 * @param   string   key1
	 * @param   string   key2 (optional)
	 * @return  array    key1 => key2 or key1,key1,key1
	 */
	public function select_list($key = '_id',$val = NULL)
	{
		if($val === NULL)
		{
			$val = $key;
			$key = NULL;
		}

		$list = array();

		foreach($this->_cursor as $data)
		{
			if($key !== NULL)
			{
				$list[(string) $data[$key]] = $data[$val];
			}
			else
			{
				$list[] = $data[$val];
			}
		}

		return $list;
	}

	/**
	 * Countable: count
	 */
	public function count( $found_only = FALSE)
	{
		return $this->_cursor->count($found_only);
	}

	/**
	 * Iterator: current
	 */
	public function current()
	{
		return Mango::factory($this->_model,$this->_cursor->current(),Mango::CLEAN);
	}

	/**
	 * Iterator: key
	 */
	public function key()
	{
		return $this->_cursor->key();
	}

	/**
	 * Iterator: next
	 */
	public function next()
	{
		return $this->_cursor->next();
	}

	/**
	 * Iterator: rewind
	 */
	public function rewind()
	{
		$this->_cursor->rewind();
	}

	/**
	 * Iterator: valid
	 */
	public function valid()
	{
		return $this->_cursor->valid();
	}

} // End ORM Iterator