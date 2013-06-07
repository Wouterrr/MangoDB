<?php defined('SYSPATH') OR die('No direct access allowed.');

class Kohana_Mango_Validation extends Kohana_Validation {

	protected $_empty_rules = array('not_empty', 'matches', 'required');

	public function offsetUnset($offset)
	{
		unset($this->_labels[$offset], $this->_rules[$offset], $this->_data[$offset]);
	}

	public function offsetSet($offset, $value)
	{
		$this->_data[$offset] = $value;
	}

}