<?php defined('SYSTEM_PATH') or die('No direct access');
/**
 * Student ORM Model
 *
 * Demo Model for example.php
 *
 * @package		MicroMVC
 * @author		David Pennington
 * @copyright	(c) 2010 MicroMVC Framework
 * @license		http://micromvc.com/license
 ********************************** 80 Columns *********************************
 */
class Model_Student extends Database_ORM {

	public $has_many = array(
		'clubs' => array('through' => 'memberships')
	);

	public $has_one = array(
		'car' => array()
	);

	public $belongs_to = array(
		'dorm' => array()
	);

	protected $_cascade_delete	= TRUE;
	
}