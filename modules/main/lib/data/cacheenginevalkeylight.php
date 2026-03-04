<?php

namespace Bitrix\Main\Data;

use Bitrix\Main\Type\DateTime;

class CacheEngineValKeyLight extends CacheEngineRedis
{
	public function __construct(array $options = [])
	{
		parent::__construct($options);
		$this->useLock = false;
	}

	protected function configure($options = []): array
	{
		$config = parent::configure($options);
		$config['serializer'] = 1;
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

		if ($filename != '')
		{
			$this->hdel($initDirKey, $filename);
		}
		elseif ($initDir != '')
		{
			$this->del($initDirKey);
		}
		else
		{
			if ($this->fullClean)
			{
				$useLock = $this->useLock;
				$this->useLock = false;

				$baseList = $this->sid . '|' . $baseDirVersion . '|' . self::BX_BASE_LIST;

				$paths = $this->getSet($baseList);
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
				$this->del($baseList);
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

		$data = serialize($vars);
		$this->rawCommand('HSETEX', $initDirKey, 'EX', $exp, 'FIELDS', '1', $filename, $data);

		if ($this->fullClean)
		{
			$baseListKey = $this->sid . '|' . $baseDirVersion . '|' . self::BX_BASE_LIST;
			$this->addToSet($baseListKey, $initDirKey);
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
			$this->read = strlen(serialize($vars));
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
			$count = 1;
		}

		$step = $count + $delta;
		for ($i = 0; $i < $step; $i++)
		{
			$finished = true;
			$paths = self::$engine->rPop($this->sid . '/cacheCleanPath');
			if ($paths)
			{
				$this->del($paths['PREFIX']);

				if ($finished)
				{
					$deleted++;
				}
				elseif (time() > $etime)
				{
					self::$engine->lPush($this->sid . '/cacheCleanPath', $paths);
					break;
				}
			}
			else
			{
				break;
			}
		}

		if ($deleted > $count)
		{
			self::$engine->setex($this->sid . '|delCount', 604800, $deleted);
		}
		elseif ($deleted < $count && $count > 1)
		{
			self::$engine->setex($this->sid . '|delCount', 604800, --$count);
		}

		if ($deleted === 0)
		{
			self::$engine->setex($this->sid . '|needClean', 3600, 'N');
		}

		$this->unlock($this->sid . '|cacheClean');
	}
}