<?php

class cache {

	/**
	 * Fetch an item from the cache
	 *
	 * @param string $id the id of the cache item
	 * @param int|bool $expires max INT age of the item (TRUE = system default)
	 * @return mixed
	 */
	public static function get($id, $expires = FALSE)
	{
		$params = array(sha1($id));

		// Build query
		$sql = 'SELECT * FROM "cache" WHERE "id" = ?';

		// If the cache has an expiration
		if($expires)
		{
			$sql .= ' AND timestamp > ?';
			$params[] = time() - $expires;
		}

		// Fetch the cache (if valid)
		if($result = Database::instance()->fetch($sql, $params))
		{
			return unserialize($result[0]->data);
		}

		return FALSE;
	}


	/**
	 * Fetch an item's age
	 */
	public static function age($id)
	{
		if($result = Database::instance()->fetch('SELECT "timestamp" FROM "cache" WHERE "id" = ?', array(sha1($id))))
		{
			return $result[0]->timestamp;
		}
	}


	public static function exists($id)
	{
		return (bool) Database::instance()->count('SELECT COUNT(*) FROM "cache" WHERE "id" = ?', array(sha1($id)));
	}

	/**
	 * Store an item in the cache
	 *
	 * @param $id the id of the cache item
	 * @param $data the item to store
	 * @param $cache_life the optional life of the item
	 * @return boolean
	 */
	public static function set($id, $data)
	{
		// Remove old cache (if any)
		cache::delete($id);

		$row = array(
			'id' => sha1($id), 
			'data' => serialize($data), 
			'timestamp' => time()
		);
			
		return Database::instance()->insert('cache', $row);
	}


	/**
	 * Delete an item from the cache
	 * @return boolean
	 */
	public static function delete($id)
	{
		return (bool) Database::instance()->delete('DELETE FROM "cache" WHERE "id" = ?', array(sha1($id)));
	}


	/**
	 * Flush all existing caches
	 * @return int
	 */
	public static function delete_all()
	{
		return Database::instance()->delete('DELETE FROM "cache" WHERE 1 = 1');
	}

}