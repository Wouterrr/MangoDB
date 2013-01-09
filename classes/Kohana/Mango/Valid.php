<?php defined('SYSPATH') OR die('No direct access allowed.');

class Kohana_Mango_Valid extends Kohana_Valid {

	/**
	 * Checks if a field is set.
	 *
	 * Modified version of Validate::not_empty, also accepts FALSE
	 * as a valid value
	 *
	 * @return  boolean
	 */
	public static function required($value)
	{
		return Valid::not_empty($value) || $value === FALSE;
	}

	/**
	 * Tests if a number has a minimum value.
	 *
	 * @return  boolean
	 */
	public static function min_value($number, $min)
	{
		return $number >= $min;
	}

	/**
	 * Tests if a number has a maximum value.
	 *
	 * @return  boolean
	 */
	public static function max_value($number, $max)
	{
		return $number <= $max;
	}
}