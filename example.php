<?php
/**
 * Example Database ORM Usage
 *
 * This database system is designed to work with the MicroMVC framework. However
 * you can still use it in non-MicroMVC projects. This file shows a college
 * system example using has_one, has_many, and belongs_to ORM relationships.
 * 
 * In order to run this file you will need to run the tables.sql file in on your
 * database using phpMyAdmin or another tool. You also need to set the matching
 * database config below.
 * 
 * After you are done testing this file and you understand how it works you can
 * remove this file and the /sql and /model directories. The only things you 
 * need are the database.php and /classes/database folders.
 * 
 * http://github.com/Xeoncross/database
 *
 * @package		MicroMVC
 * @author		David Pennington
 * @copyright	(c) 2010 MicroMVC Framework
 * @license		http://micromvc.com/license
 ********************************** 80 Columns *********************************
 */

/*
 * Create a MicroMVC compatible script
 */

//Define the base file system path
define('SYSTEM_PATH', realpath(dirname(__FILE__)). '/');
define('START_TIME', microtime(true));
define('START_MEMORY_USAGE', memory_get_usage());

// Load all classes from the classes folder
function __autoload($class)
{
	require_once('classes/'. strtolower(str_replace('_', '/', $class)). '.php');
}

function dump()
{
	foreach(func_get_args() as $value)
	{
		print '<pre>'. print_r($value, TRUE). '</pre>';
	}
}


/**
 * Database
 *
 * Here you can configure the settings for connecting to the database.
 */
$config = array(
	'default' => array(
		'dsn'        => 'mysql:host=localhost;dbname=pdorm',
		'username'   => 'root',
		'password'   => '',
		'persistent' => FALSE,
	)
);

// Create a new database instance for the models to use
$db = Database::instance('default', $config['default']);


/*
 * ----------------------------------------
 * Start ORM fun...
 * ----------------------------------------
 */



/*
 * Create three new students
 */
$mary = new Model_Student;
$mary->name = 'Mary';
$mary->save();

$john = new Model_Student;
$john->name = 'John';
$john->save();

$sam = new Model_Student;
$sam->name = 'Sam';
$sam->save();


/*
 * Give Sam and Mary a car
 */

//Save sam's car
$ford = new Model_Car;
$ford->name = 'Ford F150 Pickup';
$ford->student_id = $sam->pk();
$ford->save();

// Save Mary's car
$toyota = new Model_car;
$toyota->name = 'Toyota Tacoma';
$toyota->student_id = $mary->pk();
$toyota->save();


/*
 * Create 2 new clubs
 */
$soccer = new Model_Club;
$soccer->name = 'Freshman Soccer';
$soccer->save();

$band = new Model_Club;
$band->name = 'Marching Band';
$band->save();


/*
 * Add users to clubs (or clubs to users)
 */

// Add Sam to both clubs (both ways!)
$sam->add('club', $soccer);
$band->add('student', $sam);

//Add Mary to band
$mary->add('club', $band);

// Add John to soccer
$soccer->add('student', $john);


/*
 * Set students in Dorms
 */
$dorm = new Model_Dorm;
$dorm->name = 'Dorm 1';
$dorm->save();

$dorm2 = new Model_Dorm;
$dorm2->name = 'Dorm 2';
$dorm2->save();

// Mary is alone
$mary->dorm_id = $dorm->pk();
$mary->save();

// John and Sam in dorm 2
$john->dorm_id = $dorm2->pk();
$john->save();
$sam->dorm_id = $dorm2->pk();
$sam->save();


// Remove all objects
unset($john, $sam, $mary, $dorm, $dorm2, $soccer, $band, $ford, $toyota);











/*
 * Now show data
 */

// Load all dorms
$dorm = new Model_Dorm;
$dorms = $dorm->fetch();

if( ! $dorms)
{
	throw new Exception('What the..? Where are the dorms we just made!?');
}

print dump(count($dorms). ' dorms found');





// Remove students and all their stuff
foreach($dorms as $dorm)
{
	// Print dorm
	print '<h2>'. $dorm->name. '</h2>';

	// Fetch all students
	$students = $dorm->student();

	foreach($students as $student)
	{
		print '<b>'. $student->name. ' ('.$student->pk().')</b> is living here';

		if($student->car)
		{
			print ' and drives a '. $student->car->name. ' ('. $student->car->pk().')';
		}

		print '<br />';


		if($clubs = $student->club())
		{
			print '<ul>';
			foreach($clubs as $club)
			{
				print '<li>Joined '. $club->name. ' on '. $club->joined_on. '</li>';
			}
			print '</ul>';
		}
		
		// Remove student
		print dump('Removed student. Total of '.$student->delete().' rows deleted (includes cars & club memberships)');

	}

	// Now that we removed all students - we can delete the dorm also!
	$dorm->delete();
}

unset($dorms, $dorm, $students, $student, $clubs, $club);

/*
 * Remove clubs too!
 */

$clubs = new Model_Club;

foreach($clubs->fetch() as $club)
{
	dump('Removing '. $club->name. ': '. $club->delete());
}
unset($club, $clubs);


// End