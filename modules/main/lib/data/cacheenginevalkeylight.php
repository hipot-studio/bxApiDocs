<?php

namespace Bitrix\Main\Data;

use Bitrix\Main\Type\DateTime;

class CacheEngineValKeyLight extends CacheEngineRedis
{
	// an unresolved connection reads as unsupported: no serializer of ours carries this id
	private int $serializer = \Redis::SERIALIZER_NONE;
	private bool $connectionStateResolved = false;
	private static ?array $supportedSerializers = null;

	public function __construct(array $options = [])
	{
		parent::__construct($options);
		$this->useLock = false;
	}

	/**
	 * A connection the engine has no way to exchange values with turns the cache off instead of breaking the request:
	 * the caller replaces the engine with CacheEngineNone and warns.
	 * The connection itself is read no earlier than it is needed and no more than once.
	 */
	public function isAvailable(): bool
	{
		return parent::isAvailable() && $this->isSerializerSupported();
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

				$this->addCleanPaths($this->collectCleanPaths($baseListKey));

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

	/**
	 * The base list of this engine holds init dir keys themselves, not partitions of them.
	 */
	protected function collectCleanPaths(string $baseListKey): \Generator
	{
		$cleanFrom = new DateTime();

		foreach ($this->getSet($baseListKey) as $path)
		{
			yield [
				'PREFIX' => $path,
				'CLEAN_FROM' => $cleanFrom,
				'CLUSTER_GROUP' => static::$clusterGroup,
			];
		}
	}

	public function write($vars, $baseDir, $initDir, $filename, $ttl)
	{
		if (!$this->isSerializerSupported())
		{
			return;
		}

		$baseDirVersion = $this->getBaseDirVersion($baseDir);
		$initDirKey = $this->getKeyPrefix($baseDirVersion, $initDir);
		$exp = $this->ttlMultiplier * (int)$ttl;

		self::$engine->hSetEx($initDirKey, [$filename => $vars], ['EX' => $exp]);

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
			// the library packs and compresses on its way to the wire, so the counter holds the size of the value
			// itself, the way every engine of this family reports it
			$this->written = strlen(serialize($vars));
			$this->path = $baseDir . $initDir . $filename;
		}
	}

	public function read(&$vars, $baseDir, $initDir, $filename, $ttl)
	{
		if (!$this->isSerializerSupported())
		{
			$vars = false;

			return false;
		}

		$baseDirVersion = $this->getBaseDirVersion($baseDir);
		$key = $this->getKeyPrefix($baseDirVersion, $initDir);

		$vars = $this->hget($key, $filename);

		if (Cache::getShowCacheStat())
		{
			$this->read = strlen(serialize($vars));
			$this->path = $baseDir . $initDir . $filename;
		}

		// a stored false and a missing field are the same answer here, and both count as a miss
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
		$queueKey = $this->sid . static::CLEAN_QUEUE_KEY;
		$paths = $this->readCleanPaths($queueKey, $count + $delta);
		foreach ($paths as $prefix)
		{
			if ($prefix !== '')
			{
				$delKey[] = $prefix;
				$deleted++;
			}
		}

		$this->del($delKey);

		// the queue gives the prefixes up only once their keys are gone, and it gives them up by name
		$this->ackCleanPaths($queueKey, $paths);

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

	/**
	 * Packing belongs to the client library, so the engine only asks whether the connection carries a value of ours
	 * there and back: a serializer of another kind stores something the engine can never read as its own.
	 */
	private function isSerializerSupported(): bool
	{
		return in_array($this->getSerializer(), self::getSupportedSerializers(), true);
	}

	protected function getSerializer(): int
	{
		$this->resolveConnectionState();

		return $this->serializer;
	}

	private function resolveConnectionState(): void
	{
		if ($this->connectionStateResolved)
		{
			return;
		}

		$connection = self::$engine;
		if ($connection === null)
		{
			return;
		}

		$this->serializer = (int)$connection->getOption(\Redis::OPT_SERIALIZER);
		$this->connectionStateResolved = true;
	}

	/**
	 * Serializer ids that carry a value of the cache through the library and back.
	 * The registry is built once: it depends on the phpredis build alone and cannot change within the process.
	 */
	private static function getSupportedSerializers(): array
	{
		return self::$supportedSerializers ??= self::buildSupportedSerializers();
	}

	/**
	 * A serializer constant exists only when phpredis is built with its support.
	 */
	private static function buildSupportedSerializers(): array
	{
		$serializers = [\Redis::SERIALIZER_PHP];

		if (defined('\Redis::SERIALIZER_IGBINARY'))
		{
			$serializers[] = \Redis::SERIALIZER_IGBINARY;
		}

		if (defined('\Redis::SERIALIZER_MSGPACK'))
		{
			$serializers[] = \Redis::SERIALIZER_MSGPACK;
		}

		return $serializers;
	}
}
