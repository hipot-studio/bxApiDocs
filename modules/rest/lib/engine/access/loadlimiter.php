<?php

namespace Bitrix\Rest\Engine\Access;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Rest\OAuth\Client;
use Bitrix\Main\Application;
use Bitrix\Rest\APAuth;
use Bitrix\Rest\OAuth;
use Bitrix\Bitrix24\Feature;

/**
 * Class LoadLimiter
 * @package \Bitrix\Rest\Engine\Access;
 */
class LoadLimiter
{
	private const MODULE_ID = 'rest';
	private const BITRIX24_CONNECTOR_NAME = 'cache.redis';
	private const CACHE_EXPIRE_TIME_PREFIX = 'expire';
	private const BAN_KEY_PREFIX = 'ban';
	private const BAN_DURATION = 300; // sec, fixed ban duration on limit exceeded
	private const DEFAULT_DOMAIN = 'default';
	private const BATCH_TIME_WEIGHT = 0.5; // batch requests are counted with a reduced weight
	private static int $version = 2;
	private static int $bucketSize = 60; //sec
	private static int $bucketCount = 10;
	private static int $limitTime = 420; // total hits duration per 10 min
	private static float $minimalFixTime = 0.1;
	private static string $domain = '';
	private static ?bool $isActive = null;
	private static bool $isFinaliseInit = false;
	private static array $limitedEntityTypes = [
		APAuth\Auth::AUTH_TYPE,
		OAuth\Auth::AUTH_TYPE,
	];
	private static int $numBucket = 0;
	private static array $timeRegistered = [];

	/**
	 * Returns loads time limit per 10 min.
	 *
	 * @return int
	 */
	public static function getLimitTime(): int
	{
		if (Loader::includeModule('bitrix24'))
		{
			$result = static::$limitTime;
			$seconds = (int) Feature::getVariable('rest_load_limiter_seconds');
			if ($seconds > 0)
			{
				$result = $seconds;
			}
		}
		else
		{
			$result = (int) Option::get(self::MODULE_ID, 'load_limiter_second_limit', static::$limitTime);
		}

		return $result;
	}

	/**
	 * Checks limiter status.
	 *
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function isActive(): bool
	{
		if (is_null(static::$isActive))
		{
			if (Loader::includeModule('bitrix24'))
			{
				static::$isActive = true;
			}
			else
			{
				static::$isActive = Option::get(self::MODULE_ID, 'load_limiter_active', 'N') === 'Y';
			}
		}

		return static::$isActive;
	}

	private static function getDomain(): string
	{
		if (static::$domain === '')
		{
			if (Loader::includeModule('bitrix24') && defined('BX24_HOST_NAME'))
			{
				static::$domain = BX24_HOST_NAME;
			}
			else
			{
				static::$domain = self::DEFAULT_DOMAIN;
			}
		}

		return static::$domain;
	}

	/**
	 * Register starting doing method.
	 * @param $entityType
	 * @param $entity
	 * @param $method
	 */
	public static function registerStarting(?string $entityType, ?string $entity, ?string $method): void
	{
		if (
			static::isActive()
			&& in_array($entityType, static::$limitedEntityTypes, true)
		)
		{
			$key = static::getKey($entityType, $entity, $method);
			if (!(static::$timeRegistered[$key] ?? null))
			{
				static::$timeRegistered[$key] = [
					'entityType' => $entityType,
					'entity' => $entity,
					'method' => $method,
					'timeStart' => [],
					'timeFinish' => [],
				];
			}

			static::$timeRegistered[$key]['timeStart'][] = microtime(true);
		}
	}

	/**
	 * Register ending doing method.
	 *
	 * @param $entityType
	 * @param $entity
	 * @param $method
	 */
	public static function registerEnding(?string $entityType, ?string $entity, ?string $method): void
	{
		if (
			static::isActive()
			&& in_array($entityType, static::$limitedEntityTypes, true)
		)
		{
			$key = static::getKey($entityType, $entity, $method);
			if (static::$timeRegistered[$key])
			{
				static::$timeRegistered[$key]['timeFinish'][] = microtime(true);
			}

			if (!static::$isFinaliseInit)
			{
				static::$isFinaliseInit = true;
				Application::getInstance()->addBackgroundJob([__CLASS__, 'finalize']);
			}
		}
	}

	/**
	 * Checks block by limiter.
	 * @param $entityType
	 * @param $entity
	 * @param $method
	 * @return bool ( true - block, false - don't block)
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function is(?string $entityType, ?string $entity, ?string $method): bool
	{
		if (
			!static::isActive()
			|| !in_array($entityType, static::$limitedEntityTypes, true)
		)
		{
			return false;
		}

		$resource = static::getConnectionResource();
		$banKey = static::getKey(entityType: $entityType, entity: $entity, method: $method, prefix: self::BAN_KEY_PREFIX);

		if ($resource && $resource->exists($banKey))
		{
			return true;
		}

		$totalTime = static::getRestTime($entityType, $entity, $method);
		if ($totalTime > static::getLimitTime())
		{
			if ($resource)
			{
				// Set the ban before clearing the buckets so a concurrent request can never
				// observe an empty window with no active ban and slip through. Clear the counted
				// buckets only when the ban is actually stored: otherwise the overage would be
				// forgotten without an active ban and the next request would get a full budget.
				if ($resource->setEx($banKey, self::BAN_DURATION, '1'))
				{
					// Buckets stay in Redis for up to $bucketCount * $bucketSize seconds, well
					// past BAN_DURATION, and would otherwise trigger an immediate re-ban.
					static::resetBuckets($resource, $entityType, $entity, $method);
				}
			}

			if (Loader::includeModule('bitrix24') && function_exists('saveRestStat'))
			{
				saveRestStat(static::getDomain(), $entityType, $entity, $method, $totalTime);
			}

			return true;
		}

		// Re-check the ban: a concurrent request may have set it and cleared the buckets
		// between the first check and getRestTime(), leaving this request to read an empty
		// window and slip through while the ban is active.
		if ($resource && $resource->exists($banKey))
		{
			return true;
		}

		return false;
	}

	private static function resetBuckets(object $resource, ?string $entityType, ?string $entity, ?string $method): void
	{
		$numBucket = static::getNumBucket();
		$key = static::getKey(entityType: $entityType, entity: $entity, method: $method);
		$keyExpire = static::getKey(entityType: $entityType, entity: $entity, method: $method, prefix: self::CACHE_EXPIRE_TIME_PREFIX);

		$keysToDelete = [];
		for ($i = 0; $i < static::$bucketCount; $i++)
		{
			$keysToDelete[] = $key . ($numBucket - $i);
			$keysToDelete[] = $keyExpire . ($numBucket - $i);
		}

		$resource->del($keysToDelete);
	}

	private static function getKey(?string $entityType, ?string $entity, ?string $method, ?int $bucketNum = null, string $prefix = ''): string
	{
		return
			static::getDomain() . '|v' . static::$version . '|' . ($prefix !== '' ? $prefix . '|' : '') . '{'
			. sha1($entityType . '|' .$entity . '|' . $method) . '}'
			. '|' . $bucketNum;
	}

	/**
	 * Returns time to reset limits.
	 *
	 * @param $entityType
	 * @param $entity
	 * @param $method
	 *
	 * @return int|null
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function getResetTime(?string $entityType, ?string $entity, ?string $method): ?int
	{
		$result = null;

		if (static::isActive())
		{
			$resource = static::getConnectionResource();
			if ($resource)
			{
				// While the ban is active the buckets are already cleared, so report the ban
				// expiration as the reset time instead of falling back to null.
				$banKey = static::getKey(
					entityType: $entityType,
					entity: $entity,
					method: $method,
					prefix: self::BAN_KEY_PREFIX
				);
				$banTtl = (int) $resource->ttl($banKey);
				if ($banTtl > 0)
				{
					return time() + $banTtl;
				}

				$numBucket = static::getNumBucket();
				$key =  static::getKey(
					entityType: $entityType,
					entity: $entity,
					method: $method
				);

				$keyExpire =  static::getKey(
					entityType: $entityType,
					entity: $entity,
					method: $method,
					prefix: self::CACHE_EXPIRE_TIME_PREFIX
				);

				$bucketKeys = [];
				$bucketKeysExpire = [];
				for ($i = static::$bucketCount - 1; $i >= 0; $i--)
				{
					$bucketKeys[] = $key . ($numBucket - $i);
					$bucketKeysExpire[] = $keyExpire . ($numBucket - $i);
				}

				$allKeys = array_merge($bucketKeys, $bucketKeysExpire);
				$values = static::rawMultiGet($resource, $allKeys);

				$times = array_slice($values, 0, static::$bucketCount);
				$expireTimes = array_slice($values, static::$bucketCount);

				foreach ($times as $index => $time)
				{
					if ((float) $time > 0 && $expireTimes[$index] !== false && $expireTimes[$index] !== null)
					{
						$result = (int) $expireTimes[$index];
						break;
					}
				}
				if (!$result)
				{
					if (!empty(static::$timeRegistered))
					{
						$item = reset(static::$timeRegistered);
						if (!empty($item['timeStart']))
						{
							$firstTimeStart = reset($item['timeStart']);
							$result = $firstTimeStart + static::$bucketCount * static::$bucketSize;
						}
					}
				}
			}
		}

		return $result;
	}

	protected static function getNumBucket(): int
	{
		if (!static::$numBucket)
		{
			static::$numBucket = intdiv(time(), static::$bucketSize);
		}

		return static::$numBucket;
	}

	/**
	 * Returns methods working time.
	 * @param $entityType
	 * @param $entity
	 * @param $method
	 * @return float
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function getRestTime(?string $entityType, ?string $entity, ?string $method): float
	{
		$result = [];
		if (static::isActive())
		{
			$numBucket = static::getNumBucket();

			$key = static::getKey(entityType: $entityType, entity: $entity, method: $method);
			$resource = static::getConnectionResource();
			if ($resource)
			{
				$bucketKeys = [];
				for ($i = 0; $i < static::$bucketCount; $i++)
				{
					$bucketKeys[] = $key . ($numBucket - $i);
				}

				$values = static::rawMultiGet($resource, $bucketKeys);

				foreach ($values as $value)
				{
					$result[] = (float) $value;
				}
			}
			if (!empty(static::$timeRegistered[$key]['timeStart']))
			{
				foreach (static::$timeRegistered[$key]['timeStart'] as $k => $timeStart)
				{
					if (static::$timeRegistered[$key]['timeFinish'][$k] ?? null)
					{
						$time = static::$timeRegistered[$key]['timeFinish'][$k] - $timeStart;
						if (static::$timeRegistered[$key]['method'] === Client::METHOD_BATCH)
						{
							$time *= self::BATCH_TIME_WEIGHT;
						}
						if ($time > static::$minimalFixTime)
						{
							$result[] = $time;
						}
					}
				}
			}
		}

		return array_sum($result);
	}

	/**
	 * Saves working time by Background Job
	 *
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function finalize(): void
	{
		if (static::$timeRegistered && static::isActive())
		{
			$resource = static::getConnectionResource();
			if ($resource)
			{
				foreach (static::$timeRegistered as $item)
				{
					$time = 0;
					$firstTime = reset($item['timeStart']);
					foreach ($item['timeStart'] as $k => $timeStart)
					{
						if ($item['timeFinish'][$k])
						{
							$time += $item['timeFinish'][$k] - $timeStart;
						}
					}

					if ($item['method'] === Client::METHOD_BATCH)
					{
						$time *= self::BATCH_TIME_WEIGHT;
					}

					if ($time > static::$minimalFixTime)
					{
						$key = static::getKey(
							entityType: $item['entityType'],
							entity: $item['entity'],
							method: $item['method'],
							bucketNum: static::getNumBucket()
						);

						if ($resource->exists($key))
						{
							$resource->incrByFloat($key, $time);
						}
						else
						{
							$expireAt = $firstTime + static::$bucketCount * static::$bucketSize;
							$resource->incrByFloat($key, $time);
							$resource->expireAt($key, (int) $expireAt);

							$keyExpire = static::getKey(
								entityType: $item['entityType'],
								entity: $item['entity'],
								method: $item['method'],
								bucketNum: static::getNumBucket(),
								prefix: self::CACHE_EXPIRE_TIME_PREFIX
							);

							$resource->incrByFloat($keyExpire, $expireAt);
							$resource->expireAt($keyExpire, (int) $expireAt);
						}
					}
				}
				static::$timeRegistered = [];
			}
		}
	}

	/**
	 * Executes MGET via rawCommand, bypassing the connection's value serializer/compressor:
	 * bucket values are written by incrByFloat() as plain uncompressed floats, so a regular
	 * mGet() would try (and fail) to decompress them when compression is enabled.
	 */
	private static function rawMultiGet(object $resource, array $keys): array
	{
		if (empty($keys))
		{
			return [];
		}

		$values = $resource instanceof \RedisCluster
			? $resource->rawCommand($keys[0], 'MGET', ...$keys)
			: $resource->rawCommand('MGET', ...$keys)
		;

		return is_array($values) ? $values : array_fill(0, count($keys), false);
	}

	protected static function getConnectionResource(): ?object
	{
		static $isInitialized = false;
		static $resource = null;

		if ($isInitialized)
		{
			return $resource;
		}

		$isInitialized = true;
		$connectionName = static::getConnectionName();
		if ($connectionName)
		{
			$connection = Application::getInstance()
				->getConnectionPool()
				->getConnection($connectionName);

			if ($connection)
			{
				$resource = $connection->getResource();
				if ($connection->isConnected() !== true)
				{
					$resource = null;
				}
			}
		}

		return $resource;
	}

	private static function getConnectionName(): string
	{
		if (Loader::includeModule('bitrix24'))
		{
			return self::BITRIX24_CONNECTOR_NAME;
		}

		return Option::get(self::MODULE_ID, 'load_limiter_connection_name', '');
	}
}