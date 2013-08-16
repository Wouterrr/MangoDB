<?php defined('SYSPATH') OR die('No direct script access.');

interface Mango_Interface
{
	public function as_array( $clean = TRUE );

	public function changed($update, array $prefix = array());

	public function saved();
}
