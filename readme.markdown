## MicroMVC Database (aka PDORM)

This database system is designed to work with the MicroMVC framework. However you can still use it in non-MicroMVC projects. This is, in fact, encouraged as there are too many poorly implemented database libraries in great projects. Like the tiny MicroMVC framework, these classes are written to be as lightweight as possible.

The main library consists of a Database abstraction layer built over PDO to work with SQLite, MySQL, and PostgreSQL. This base Database class allows the Active Record styled query building while still encouraging the use of straight SQL queries.

The other main class is the ORM class designed to help reduce the code that must be written when building your models. It features full support for has_one, has_many, has_many (through), and belongs_to relationships - all in about 20KB (with heavy commenting).

Both the Database library and the ORM are built for Prepared Statements. This fact alone sets them apart from almost every other library out there. You can use old-fashioned direct string injection - but it is frowned upon.

Included in this library are a couple example files which can be removed if not needed.

 - /classes/models/*
 - /sql/*
 - example.php

Please feel free to submit any patches you develop that improve these classes.
