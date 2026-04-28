<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Main\Data;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache\CacheEntry;

class ManagedCache
{
	protected const BASE_DIR = "managed_cache";
	protected const FLAG_DIR = "_flags";
	protected const FLAG_TTL = 30;

	/** @var CacheEntry[] */
	protected $cache = [];
	protected $vars = [];
	protected $dbType;
	protected $flagDir;

	public function __construct()
	{
		$this->dbType = strtoupper(Application::getInstance()->getConnection()->getType());
		$this->flagDir = $this->dbType . '/' . static::FLAG_DIR;
	}

	// Tries to read cached variable value from the file
	// Returns true on success
	// otherwise returns false
	public function read($ttl, $uniqueId, $tableId = false)
	{
		if (!isset($this->cache[$uniqueId]))
		{
			$this->cache[$uniqueId] = (new CacheEntry($ttl, $uniqueId, $this->getDir($tableId), static::BASE_DIR))
				->initialize()
			;
		}

		return $this->cache[$uniqueId]->isInitialized() || array_key_exists($uniqueId, $this->vars);
	}

	public function getImmediate($ttl, $uniqueId, $tableId = false)
	{
		$cacheEntry = (new CacheEntry($ttl, $uniqueId, $this->getDir($tableId), static::BASE_DIR))
			->initialize()
		;

		if ($cacheEntry->isInitialized())
		{
			return $cacheEntry->getVars();
		}

		return false;
	}

	/**
	 * This method is used to read the variable value
	 * from the cache after successfull Read
	 *
	 * @param string $uniqueId
	 * @return mixed
	 */
	public function get($uniqueId)
	{
		if (array_key_exists($uniqueId, $this->vars))
		{
			return $this->vars[$uniqueId];
		}
		elseif (isset($this->cache[$uniqueId]) && $this->cache[$uniqueId]->isInitialized())
		{
			return $this->cache[$uniqueId]->getVars();
		}

		return false;
	}

	// Sets new value to the variable
	public function set($uniqueId, $val)
	{
		if (isset($this->cache[$uniqueId]))
		{
			$this->vars[$uniqueId] = $val;
		}
	}

	public function setImmediate($uniqueId, $val)
	{
		if (!isset($this->cache[$uniqueId]))
		{
			return;
		}

		$initTime = $this->cache[$uniqueId]->getInitTime();
		$write = true;

		// real value changed - cache entry was deleted
		if ($this->checkFlag('flag.' . $uniqueId, $initTime))
		{
			$write = false;
		}
		else
		{
			$cachePath = $this->cache[$uniqueId]->getCachePath();

			// real value changed - cache directory was deleted
			if ($this->checkFlag('dir.' . $cachePath, $initTime))
			{
				$write = false;
			}
		}

		if ($write)
		{
			$this->cache[$uniqueId]->write($val);
		}

		unset($this->cache[$uniqueId]);
		unset($this->vars[$uniqueId]);
	}

	protected function writeFlag(string $key): void
	{
		(new CacheEntry(static::FLAG_TTL, $key, $this->flagDir, static::BASE_DIR))
			->write(hrtime(true))
		;
	}

	protected function checkFlag(string $key, float $startTime): bool
	{
		$cacheEntry = (new CacheEntry(static::FLAG_TTL, $key, $this->flagDir, static::BASE_DIR))
			->initialize()
		;

		if ($cacheEntry->isInitialized())
		{
			$cleanTime = $cacheEntry->getVars();

			if ($startTime <= $cleanTime)
			{
				return true;
			}
		}

		return false;
	}

	// Marks cache entry as invalid
	public function clean($uniqueId, $tableId = false)
	{
		// Write a flag to indicate that the real value has changed. We'll check this flag on writing.
		$this->writeFlag('flag.' . $uniqueId);

		Cache::createInstance()->clean($uniqueId, $this->getDir($tableId), static::BASE_DIR);

		if (isset($this->cache[$uniqueId]))
		{
			unset($this->cache[$uniqueId]);
			unset($this->vars[$uniqueId]);
		}
	}

	// Marks cache entries associated with the table as invalid
	public function cleanDir($tableId)
	{
		$dir = $this->getDir($tableId);

		// Write a flag to indicate that the real value has changed. We'll check this flag on writing.
		$this->writeFlag('dir.' . $dir);

		Cache::createInstance()->cleanDir($dir, static::BASE_DIR);

		foreach ($this->cache as $uniqueId => $cacheEntry)
		{
			if ($cacheEntry->getCachePath() == $dir)
			{
				unset($this->cache[$uniqueId]);
				unset($this->vars[$uniqueId]);
			}
		}
	}

	// Clears all managed_cache
	public function cleanAll()
	{
		Cache::createInstance()->cleanDir(false, static::BASE_DIR);

		$this->cache = [];
		$this->vars = [];
	}

	// Use it to flush cache to the files.
	// Causion: only at the end of all operations!
	public static function finalize()
	{
		$cacheManager = Application::getInstance()->getManagedCache();

		foreach ($cacheManager->cache as $uniqueId => $cacheEntry)
		{
			if (array_key_exists($uniqueId, $cacheManager->vars))
			{
				$cacheManager->setImmediate($uniqueId, $cacheManager->vars[$uniqueId]);
			}
		}
	}

	public function getCompCachePath($relativePath)
	{
		// TODO: global var!
		global $BX_STATE;

		if ($BX_STATE === "WA")
		{
			$salt = Cache::getSalt();
		}
		else
		{
			$salt = "/" . mb_substr(md5($BX_STATE), 0, 3);
		}

		$path = "/" . SITE_ID . $relativePath . $salt;

		return $path;
	}

	protected function getDir($tableId): string
	{
		return $this->dbType . ($tableId === false ? "" : "/" . $tableId);
	}
}
