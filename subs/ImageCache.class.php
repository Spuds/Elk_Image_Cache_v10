<?php

/**
 * Image cache proxy core functionality
 *
 * @name ImageCache
 * @author Spuds
 * @copyright (c) 2017 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.0
 *
 */

/**
 * Class Image_Cache
 *
 * Provides all functions relating to running the image cache proxy
 */
class Image_Cache
{
	public $height = 480;
	public $width = 640;
	public $max_retry = 10;
	private $destination;
	private $success = false;
	private $hash;
	private $log_time;
	private $num_fail;
	private $data;
	protected $_db = null;
	protected $_modSettings = array();

	/**
	 * Image_Cache constructor.
	 *
	 * @param Database|null $db
	 * @param string   $file
	 */
	public function __construct($db = null, $file = '')
	{
		global $modSettings;

		$this->_db = $db ? $db : database();
		$this->_modSettings = new ArrayObject($modSettings ? $modSettings : array(), ArrayObject::ARRAY_AS_PROPS);

		$this->data = $file;
		$this->hash = $this->_imageCacheHash();
		$this->destination = CACHEDIR . '/img_cache_' . $this->hash . '.elk';
	}

	/**
	 * Creates a hash code using the image name and our secret key
	 *
	 * - If no salt has been set, creates a random one for use, and sets it
	 * in modsettings for future use
	 *
	 * @return string
	 */
	private function _imageCacheHash()
	{
		// What no hash sauce, then go ask Alice
		if (empty($this->_modSettings['imagecache_sauce']))
		{
			// Generate a 10 digit random hash.
			$imagecache_sauce = uniqid ();
			$imagecache_sauce = substr($imagecache_sauce, 0, 10);

			// Save it for all future uses
			updateSettings(array('imagecache_sauce' => $imagecache_sauce));
			$this->_modSettings['imagecache_sauce'] = $imagecache_sauce;
		}

		return hash_hmac('md5', $this->data, $this->_modSettings['imagecache_sauce']);
	}

	/**
	 * To avoid blocking in parseBBC, we seed the cache table
	 * and save a default image.  When the cache is requested
	 * the first time the image will be fetched (page load)
	 */
	public function seedImageCacheTable()
	{
		$cache_hit = $this->getImageFromCacheTable();

		// A false result means we are seeding, otherwise it does exist
		if ($cache_hit === false)
		{
			// If its to large, flag it as "done" so we don't try to fetch the file
			// otherwise we seed with past time to ensure its retried immediately
			if (!empty($this->_modSettings['image_cache_maxsize']) && $this->sniffSize() > $this->_modSettings['image_cache_maxsize'])
			{
				$input = array($this->hash, time(), 0);
			}
			else
			{
				$input = array($this->hash, time() - 120, 1);
			}

			// Make the entry
			$this->_db->insert('ignore',
				'{db_prefix}image_cache',
				array('filename' => 'string', 'log_time' => 'int', 'num_fail' => 'int'),
				$input,
				array('filename')
			);

			$this->_setTemporaryImage();
		}
	}

	/**
	 * Check to see how large this image is
	 *
	 * @return float|int
	 */
	public function sniffSize()
	{
		$size = 0;
		stream_context_set_default(array('http' => array('method' => 'HEAD')));
		$head = @get_headers($this->data, 1);

		if ($head !== false)
		{
			$head = array_change_key_case($head);

			// Read from Content-Length: if it exists
			$size = isset($head['content-length']) && is_numeric ($head['content-length']) ? $head['content-length'] : 0;
			$size = round($size / 1048576, 2);
		}

		// Size in MB or 0 if we don't know
		return $size;
	}

	/**
	 * Removes and image entry from the cache table
	 */
	public function removeImageFromCacheTable()
	{
		$this->_db->query('', '
			DELETE FROM {db_prefix}image_cache
			WHERE filename = {string:filename}',
			array(
				'filename' => $this->hash,
			)
		);
	}

	/**
	 * Checks if the image has previously been saved.
	 *
	 * - true if we have successfully (previously) saved the image
	 * - false if there is no record of the image
	 * - int, the number of times we have failed in trying to fetch the image
	 *
	 * @return bool|int
	 */
	public function getImageFromCacheTable()
	{
		$request = $this->_db->query('', '
			SELECT
			 	filename, log_time, num_fail
			FROM {db_prefix}image_cache
			WHERE filename = {string:filename}',
			array(
				'filename' => $this->hash,
			)
		);
		if ($this->_db->num_rows($request) == 0)
		{
			$this->num_fail = false;
		}
		else
		{
			list(, $this->log_time, $this->num_fail) = $this->_db->fetch_row($request);
			$this->num_fail = empty($this->num_fail) ? true : (int) $this->num_fail;
		}
		$this->_db->free_result($request);

		return $this->num_fail;
	}

	/**
	 * Will retry to fetch a previously failed attempt at caching an image
	 *
	 * What it does:
	 * - If the number of attempts has been exceeded, gives up
	 * - If the time gate / attempt value allows another attempt, does so
	 * - Attempts to ensure only one request makes the next attempt to avoid race/contention issues
	 */
	public function retryCreateImageCache()
	{
		// Time to give up ?
		if ($this->num_fail > $this->max_retry)
		{
			$this->success = false;
			return;
		}

		// The more failures the longer we wait before the next attempt,
		// 10 attempts ending 1 week out from initial failure, approx as
		// 1min, 16min, 1.3hr, 4.2hr, 10.5hr, 21.6hr, 40hr, 2.8day, 4.5day, 1wk
		$delay = pow($this->num_fail, 4) * 60;
		$last_attempt = time() - $this->log_time;

		// Time to try again
		if ($last_attempt > $delay)
		{
			// Optimistic "locking" to try and prevent any race conditions
			$this->_db->query('', '
				UPDATE {db_prefix}image_cache
				SET num_fail = num_fail + 1
				WHERE filename = {string:filename}
					AND num_fail = {int:num_fail}',
				array(
					'filename' => $this->hash,
					'num_fail' => $this->num_fail
				)
			);

			// Only if we have success in updating the fail count is the attempt "ours" to make
			if ($this->_db->affected_rows() != 0)
			{
				$this->createCacheImage();
			}
		}
	}

	/**
	 * Main process loop for fetching and caching an image
	 */
	public function createCacheImage()
	{
		require_once(SUBSDIR . '/Graphics.subs.php');

		// Constrain the image to fix to our maximums
		$this->_setImageDimensions();

		// Keep png's as png's, all others to jpg
		$extension = pathinfo($this->data, PATHINFO_EXTENSION) === 'png' ? 3 : 2;

		// Create an image for the cache, resize if needed
		$this->success = resizeImageFile($this->data, $this->destination, $this->width, $this->height, $extension);

		// Log success or failure
		$this->_actOnResult();
	}

	/**
	 * If we have the image or not
	 *
	 * @return bool
	 */
	public function returnStatus()
	{
		return $this->success;
	}

	/**
	 * Sets the saved image width/height based on acp settings or defaults
	 */
	private function _setImageDimensions()
	{
		$this->width = !empty($this->_modSettings['max_image_height']) ? $this->_modSettings['max_image_width'] : $this->width;
		$this->height = !empty($this->_modSettings['max_image_height']) ? $this->_modSettings['max_image_height'] : $this->height;
	}

	/**
	 * Based on success or failure on creating a cache image, determines next steps
	 */
	private function _actOnResult()
	{
		// Add or update the entry
		$this->_updateImageCacheTable();

		// Failure! ... show em a default mime thumbnail instead
		if ($this->success === false)
		{
			$this->_setTemporaryImage();
		}
	}

	/**
	 * Updates the image cache db table with the results of the attempt
	 */
	private function _updateImageCacheTable()
	{
		// Always update the line with success
		if ($this->success === true)
		{
			$this->_db->insert('replace',
				'{db_prefix}image_cache',
				array('filename' => 'string', 'log_time' => 'int', 'num_fail' => 'int'),
				array($this->hash, time(), 0),
				array('filename')
			);
		}

		// Add the line only if its the first time to fail
		if ($this->success === false)
		{
			$this->_db->insert('ignore',
				'{db_prefix}image_cache',
				array('filename' => 'string', 'log_time' => 'int', 'num_fail' => 'int'),
				array($this->hash, time(), 1),
				array('filename')
			);
		}
	}

	/**
	 * On failure, saves our default mime image for use
	 */
	private function _setTemporaryImage()
	{
		global $settings;

		$source = $settings['theme_dir'] . '/images/mime_images/default.png';

		@copy($source, $this->destination);
	}

	/**
	 * Returns the current file hash
	 *
	 * @return string
	 */
	public function getImageCacheHash()
	{
		return $this->hash;
	}

	/**
	 * Update the log date, indicating last access only for a successful cache hit
	 *
	 * - Keeping the date of successful entries are used in maximum age
	 * - Updates once an hour to minimize db work
	 */
	public function updateImageCacheHitDate()
	{
		// Its in the cache and has not been touched in at least an hour
		if ($this->num_fail === true && $this->log_time + 3600 < time())
		{
			$this->_db->query('', '
				UPDATE {db_prefix}image_cache
				SET log_time = {int:log_time}
				WHERE filename = {string:filename}',
				array(
					'filename' => $this->hash,
					'log_time' => time(),
				)
			);
		}

		$this->success = true;
	}

	/**
	 * Removes ALL image cache entries from the filesystem and db table.
	 *
	 * @return bool
	 */
	public function pruneImageCache()
	{
		// Remove '/img_cache_' files in our disk cache directory
		try
		{
			$files = new GlobIterator(CACHEDIR . '/img_cache_*.elk', FilesystemIterator::SKIP_DOTS);

			foreach ($files as $file)
			{
				if ($file->getFileName() !== 'index.php' && $file->getFileName() !== '.htaccess')
				{
					@unlink($file->getPathname());
				}
			}
		}
		catch (UnexpectedValueException $e)
		{
			// @todo
		}

		// Finish off by clearing the image_cache table of all entries
		$this->_db->query('truncate_table', '
			TRUNCATE {db_prefix}image_cache',
			array(
			)
		);

		clearstatcache();

		return true;
	}
}
