<?php

namespace Bitrix\Main\Data;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Data\LocalStorage\Storage;

/**
 * @property \Redis $engine
 */
class CacheEngineRedis extends Cache\KeyValueEngine implements Storage\CacheEngineInterface
{
	protected const CLEAN_QUEUE_KEY = '/cacheCleanPath_v1';

	public function getConnectionName(): string
	{
		return 'cache.redis';
	}

	public static function getConnectionClass()
	{
		return RedisConnection::class;
	}

	protected function configure($options = []): array
	{
		$config = parent::configure($options);

		$cacheConfig = Configuration::getValue('cache');

		if (isset($cacheConfig['serializer']))
		{
			$config['serializer'] = (int)$cacheConfig['serializer'];
		}

		$config['persistent'] = true;
		if (isset($cacheConfig['persistent']) && $cacheConfig['persistent'] == 0)
		{
			$config['persistent'] = false;
		}

		if (isset($cacheConfig['compression']))
		{
			$config['compression'] = $cacheConfig['compression'];
		}

		if (isset($cacheConfig['compression_level']))
		{
			$config['compression_level'] = $cacheConfig['compression_level'];
		}

		if (isset($cacheConfig['timeout']))
		{
			$cacheConfig['timeout'] = (float)$cacheConfig['timeout'];
			if ($cacheConfig['timeout'] > 0)
			{
				$config['timeout'] = $cacheConfig['timeout'];
			}
		}

		if (isset($cacheConfig['read_timeout']))
		{
			$cacheConfig['read_timeout'] = (float)$cacheConfig['read_timeout'];
			if ($cacheConfig['read_timeout'] > 0)
			{
				$config['read_timeout'] = $cacheConfig['read_timeout'];
			}
		}

		return $config;
	}

	public function set($key, $ttl, $value): bool
	{
		$ttl = (int)$ttl;
		if ($ttl > 0)
		{
			return self::$engine->setex($key, $ttl, $value);
		}
		return self::$engine->set($key, $value);
	}

	public function get($key)
	{
		return self::$engine->get($key);
	}

	public function del($key)
	{
		self::$engine->unlink($key);
	}

	/**
	 * The key and its expiration are set by one command: a separate expire may never run,
	 * and a lock left without expiration is held forever.
	 */
	public function setNotExists($key, $ttl, $value)
	{
		$ttl = (int)$ttl;
		$options = $ttl > 0 ? ['nx', 'ex' => $ttl] : ['nx'];

		return (bool)self::$engine->set($key, $value, $options);
	}

	public function checkInSet($key, $value): bool
	{
		return self::$engine->sIsMember($key, $value);
	}

	public function addToSet($key, $value)
	{
		self::$engine->sAdd($key, $value);
	}

	public function getSet($key): array
	{
		$list = self::$engine->sMembers($key);
		if (!is_array($list))
		{
			$list = [];
		}
		return $list;
	}

	public function deleteBySet($key, $prefix = '')
	{
		$list = self::$engine->sMembers($key);
		self::$engine->unlink($key);

		if (is_array($list) && !empty($list))
		{
			self::$engine->unlink($list);
		}
	}

	public function delFromSet($key, $member)
	{
		if (!is_array($member))
		{
			$member = [0 => $member];
		}

		if (!empty($member))
		{
			self::$engine->sRem($key, ...$member);
		}
	}

	public function addCleanPath(array $data): void
	{
		self::$engine->sAdd($this->sid . static::CLEAN_QUEUE_KEY, $data['PREFIX']);
	}

	public function addCleanPaths(iterable $items): void
	{
		foreach ($this->chunkCleanPaths($items) as $chunk)
		{
			self::$engine->sAdd($this->sid . static::CLEAN_QUEUE_KEY, ...array_column($chunk, 'PREFIX'));
		}
	}

	/**
	 * Reads up to $count prefixes of the clean queue without taking them off it.
	 * A prefix leaves the queue only once its keys are gone: sweeping a prefix twice is safe, losing it is not,
	 * and a process that dies mid-step would take the whole batch with it.
	 */
	protected function readCleanPaths(string $key, int $count): array
	{
		$paths = self::$engine->sRandMember($key, $count);

		return is_array($paths) ? $paths : [];
	}

	/**
	 * Takes the swept prefixes off the queue by name. Naming them is what makes a second sweeper harmless:
	 * a prefix nobody swept is never removed, however the two steps interleave.
	 */
	protected function ackCleanPaths(string $key, array $paths): void
	{
		if ($paths)
		{
			self::$engine->sRem($key, ...$paths);
		}
	}

	public function delayedDelete(): void
	{
		$delta = 10;
		$deleted = 0;
		$etime = time() + 5;
		$interrupted = false;
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

		$queueKey = $this->sid . static::CLEAN_QUEUE_KEY;
		$queue = $this->readCleanPaths($queueKey, $count + $delta);
		$swept = [];

		foreach ($queue as $prefix)
		{
			if ($prefix === '')
			{
				$swept[] = $prefix;

				continue;
			}

			$finished = true;
			$partitionListKey = $prefix . '|' . static::BX_INIT_DIR_LIST;

			foreach ($this->getSet($partitionListKey) as $partition)
			{
				if (time() > $etime)
				{
					$interrupted = true;
					$finished = false;
					break;
				}

				$this->deleteBySet($partitionListKey . '|' . $partition);
			}

			// an unfinished prefix stays in the queue and the next step takes it again
			if (!$finished)
			{
				break;
			}

			$swept[] = $prefix;
			$deleted++;
		}

		$this->ackCleanPaths($queueKey, $swept);

		if ($deleted > $count)
		{
			self::$engine->setex($this->sid . '|delCount', 604800, $deleted);
		}
		elseif ($interrupted && $deleted < $count && $count > 1)
		{
			self::$engine->setex($this->sid . '|delCount', 604800, --$count);
		}

		// the queue is idle only when it had nothing to offer: an unfinished prefix keeps the flag on
		if ($queue === [])
		{
			self::$engine->setex($this->sid . '|needClean', 3600, 'N');
		}

		$this->unlock($this->sid . '|cacheClean');
	}

	public function hdel($key, $field)
	{
		return self::$engine->hdel($key, $field);
	}

	public function hget($key, $field)
	{
		return self::$engine->hget($key, $field);
	}

	public function rawCommand(string $command, mixed ...$args)
	{
		return self::$engine->rawCommand($command, ...$args);
	}
}