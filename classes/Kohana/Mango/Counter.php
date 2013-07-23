<?php 

class Kohana_Mango_Counter implements Mango_Interface {

	/**
	 * @var   float   Current value of counter
	 */
	protected $_value = 0;

	/**
	 * @var   float   Value added to/substracted from counter
	 */
	protected $_changed = 0;

	/**
	 * Constructor
	 */
	public function __construct($value = NULL)
	{
		if ( $value instanceof Mango_Counter)
		{
			$value = (string) $value;
		}

		if ( is_numeric($value))
		{
			$this->_value = (float) $value;
		}
	}

	/**
	 * Increment counter with value
	 *
	 * @param   int   Value to increment counter with (default: 1)
	 * @return  void
	 */
	public function increment($value = 1)
	{
		$this->_value += $value;
		$this->_changed += $value;
	}

	/**
	 * Decrement counter with value
	 *
	 * @param   int   Value to decrement counter with (default: 1)
	 * @return  void
	 */
	public function decrement($value = 1)
	{
		$this->_value -= $value;
		$this->_changed -= $value;
	}

	/**
	 * Returns value of counter, named 'as_array()' as to Mango_Interface
	 *
	 * @return  int
	 */
	public function as_array( $debug = FALSE )
	{
		return $this->_value;
	}

	/**
	 * Returns value of counter
	 *
	 * @return  int
	 */
	public function as_int()
	{
		return $this->_value;
	}

	/*
	 * Return array of changes
	 */
	public function changed($update, array $prefix = array())
	{
		$path = implode('.',$prefix);
		$data = array();

		if ( ! empty($this->_changed))
		{
			if ( $update)
			{
				$data['$inc'] = array($path => $this->_changed);
			}
			else
			{
				Arr::set_path($data, $path, $this->_value);
			}
		}

		return $data;
	}

	public function saved()
	{
		$this->_changed = 0;
	}

	public function __toString()
	{
		return (string) $this->_value;
	}
}