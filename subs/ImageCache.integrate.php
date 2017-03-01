<?php

/**
 * Provides a simple image cache, intended for serving http images over https.
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
 * Class Image_Cache_Integrate
 *
 * - collection of static methods called from added <hook> code in package-info
 */
class Image_Cache_Integrate
{
	static public $js_load = false;

	/**
	 * Determines if the image would require cache usage
	 *
	 * - Used by the updated BBC img codes added by imagecache_integrate_bbc_codes
	 *
	 * @return Closure
	 */
	public static function imageNeedsCache()
	{
		global $boardurl, $txt, $modSettings;

		// Trickery for 5.3
		$js_loaded =& self::$js_load;
		$always = !empty($modSettings['image_cache_all']);

		// Return a closure function for the bbc code
		return function (&$tag, &$data, $disabled) use ($boardurl, $txt, &$js_loaded, $always)
		{
			$data = Image_Cache_Integrate::addProtocol($data);

			$parseBoard = parse_url($boardurl);
			$parseImg = parse_url($data);

			// No need to cache an image that is not going over https, or is already https over https
			if (!$always && ($parseBoard['scheme'] === 'http' || $parseBoard['scheme'] === $parseImg['scheme']))
			{
				return false;
			}

			// Flag the loading of js
			if ($js_loaded === false)
			{
				$js_loaded = true;
				loadJavascriptFile('imagecache.js', array('defer' => true));
			}

			// Use the image cache to generate hash and seed if needed
			require_once(SUBSDIR . '/ImageCache.class.php');
			$proxy = new Image_Cache(database(), $data);
			$proxy->seedImageCacheTable();

			$data = $boardurl . '/imagecache.php?image=' . urlencode($data) . '&hash=' . $proxy->getImageCacheHash() . '" rel="cached" data-warn="' . Util::htmlspecialchars($txt['image_cache_warn_ext']) . '" data-url="' . Util::htmlspecialchars($data);

			return true;
		};
	}

	/**
	 * Adds a protocol (http/s) to the beginning of an url if it is missing
	 *
	 * @param string $url - The url
	 * @return string - The url with the protocol
	 */
	public static function addProtocol($url)
	{
		$pattern = '~^(http://|https://)~i';
		$protocols = array('http://');

		$found = false;
		$url = preg_replace_callback($pattern, function($match) use (&$found) {
			$found = true;

			return strtolower($match[0]);
		}, $url);

		if ($found === true)
		{
			return $url;
		}

		return $protocols[0] . $url;
	}

	/**
	 * Update all IMG tags to use our cache validation function
	 *
	 * @param array $codes
	 */
	public static function imagecache_integrate_bbc_codes(&$codes)
	{
		loadLanguage('ImageCache');
		loadCSSFile('imagecache.css');

		foreach ($codes as $key => $code)
		{
			if ($code['tag'] === 'img')
			{
				$codes[$key]['validate'] = self::imageNeedsCache();
			}
		}
	}

	/**
	 * Used to add the ImageCache entry to the admin menu.
	 *
	 * @param mixed[] $admin_areas The admin menu array
	 */
	public static function imagecache_integrate_admin_areas(&$admin_areas)
	{
		global $txt;

		loadLanguage('ImageCache');

		// Set a new admin area
		$admin_areas['config']['areas']['manageimagecache'] = array(
			'label' => $txt['image_cache_title'],
			'controller' => 'ManageImageCacheModule_Controller',
			'function' => 'action_index',
			'icon' => 'transparent.png',
			'class' => 'admin_img_logs',
			'permission' => array('admin_forum'),
			'file' => 'ManageImageCacheModule.controller.php',
		);
	}

	/**
	 * Used to add the ImageCache entry to the admin search.
	 *
	 * @param string[] $language_files
	 * @param string[] $include_files
	 * @param mixed[] $settings_search
	 */
	public static function imagecache_integrate_admin_search(&$language_files, &$include_files, &$settings_search)
	{
		$language_files[] = 'ImageCache';
		$include_files[] = 'ManageImageCacheModule.controller';
		$settings_search[] = array('settings_search', 'area=manageimagecache', 'ManageImageCacheModule_Controller');
	}

	/**
	 * Integration hook, integrate_list_scheduled_tasks, called from ManageScheduledTasks.controller,
	 * (actually called from createlist)
	 */
	public static function imagecache_integrate_list_scheduled_tasks()
	{
		// Just need our language strings for the listing
		loadLanguage('ImageCache');
	}
}
