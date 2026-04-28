<?php

namespace Bitrix\Main\Data;

use Bitrix\Main\Type\DateTime;

class CacheEngineValKeyLight extends CacheEngineRedis
{
	protected int $serializer = \Redis::SERIALIZER_PHP;

	public function __construct(array $options = [])
	{
		parent::__construct($options);
		$this->useLock = false;
	}

	protected function configure($options = []): array
	{
		$config = parent::configure($options);

		$this->serializer = $config['serializer'] ?? (defined('\Redis::SERIALIZER_IGBINARY') ? \Redis::SERIALIZER_IGBINARY : \Redis::SERIALIZER_PHP);

		return $config;
	}

	public function clean($baseDir, $initDir = false, $filename = false)
	{
		if (!self::isAvailable())
		{
			return;
		}

		$baseDirVersion = $this->getBaseDirVersion($baseDir);
		$initDirKey = $this->getKeyPrefix($baseDirVersion, $initDir);
		$baseListKey = $this->sid . '|' . $baseDirVersion . '|' . self::BX_BASE_LIST;

		if ($filename != '')
		{
			$this->hdel($initDirKey, $filename);
		}
		elseif ($initDir != '')
		{
			$this->del($initDirKey);
			if ($this->fullClean)
			{
				unset(static::$cleanPath[$baseListKey][$initDirKey]);
			}
		}
		else
		{
			if ($this->fullClean)
			{
				$useLock = $this->useLock;
				$this->useLock = false;

				$paths = $this->getSet($baseListKey);
				foreach ($paths as $path)
				{
					$this->addCleanPath([
						'PREFIX' => $path,
						'CLEAN_FROM' => (new DateTime()),
						'CLUSTER_GROUP' => static::$clusterGroup,
					]);
				}

				unset($paths);

				$this->set($this->sid . '|needClean', 3600, 'Y');
				$this->del($baseListKey);
				unset(static::$cleanPath[$baseListKey]);
				$this->useLock = $useLock;
			}

			$baseDirKey = $this->getBaseDirKey($baseDir);
			$this->del($baseDirKey);

			unset(static::$baseDirVersion[$baseDirKey]);
		}
	}

	public function write($vars, $baseDir, $initDir, $filename, $ttl)
	{
		$baseDirVersion = $this->getBaseDirVersion($baseDir);
		$initDirKey = $this->getKeyPrefix($baseDirVersion, $initDir);
		$exp = $this->ttlMultiplier * (int)$ttl;

		$data = $this->serialize($vars);
		$this->rawCommand('HSETEX', $initDirKey, 'EX', $exp, 'FIELDS', '1', $filename, $data);

		if ($this->fullClean)
		{
			$baseListKey = $this->sid . '|' . $baseDirVersion . '|' . self::BX_BASE_LIST;

			if (!isset(self::$cleanPath[$baseListKey][$initDirKey]))
			{
				$this->addToSet($baseListKey, $initDirKey);
				self::$cleanPath[$baseListKey][$initDirKey] = true;
			}
		}

		if (Cache::getShowCacheStat())
		{
			$this->written = strlen($data);
			$this->path = $baseDir . $initDir . $filename;
		}
	}

	public function read(&$vars, $baseDir, $initDir, $filename, $ttl)
	{
		$baseDirVersion = $this->getBaseDirVersion($baseDir);
		$key = $this->getKeyPrefix($baseDirVersion, $initDir);
		$vars = $this->hget($key, $filename);

		if (Cache::getShowCacheStat())
		{
			$this->read = strlen($this->serialize($vars));
			$this->path = $baseDir . $initDir . $filename;
		}

		return $vars !== false;
	}

	public function delayedDelete(): void
	{
		$delta = 10;
		$deleted = 0;
		$etime = time() + 5;
		$needClean = self::$engine->get($this->sid . '|needClean');

		if ($needClean !== 'Y')
		{
			$this->unlock($this->sid . '|cacheClean');
			return;
		}

		$count = (int)self::$engine->get($this->sid . '|delCount');
		if ($count < 1)
		{
			$count = 10;
		}

		$delKey = [];
		$step = $count + $delta;
		$paths = self::$engine->rPop($this->sid . '/cacheCleanPath', $step);
		foreach (($paths ?: []) as $path)
		{
			if ($path)
			{
				$delKey[] = $path['PREFIX'];
				$deleted++;
			}
		}

		$this->del($delKey);

		if ($deleted > $count && time() < $etime)
		{
			self::$engine->setex($this->sid . '|delCount', 604800, $deleted);
		}
		elseif (($deleted < $count || time() > $etime) && $count > 1)
		{
			self::$engine->setex($this->sid . '|delCount', 604800, --$count);
		}

		if ($deleted === 0)
		{
			self::$engine->setex($this->sid . '|needClean', 3600, 'N');
		}

		$this->unlock($this->sid . '|cacheClean');
	}

	protected function serialize($data): string|false
	{
		if ($this->serializer == \Redis::SERIALIZER_IGBINARY)
		{
			return igbinary_serialize($data);
		}

		return serialize($data);
	}
}
