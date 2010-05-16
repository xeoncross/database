<?php defined('SYSTEM_PATH') or die('No direct access');
/**
 * Memberships ORM Model
 *
 * Demo Model for example.php
 *
 * @package		MicroMVC
 * @author		David Pennington
 * @copyright	(c) 2010 MicroMVC Framework
 * @license		http://micromvc.com/license
 ********************************** 80 Columns *********************************
 */
class Model_Membership extends Database_ORM
{
	public $belongs_to = array(
		'club' => array(),
		'student' => array()
	);
}