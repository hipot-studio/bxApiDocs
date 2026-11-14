<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Limiter;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Rest\APAuth;
use Bitrix\Rest\OAuth;
use Bitrix\Rest\OAuth\Client;
use Bitrix\Rest\V3\Exception\RateLimitException;
use Psr\Log\LoggerInterface;

class LoadLimiter implements LimiterInterface
{
	private const VERSION = 2;
	private const BUCKET_SIZE = 60;
	private const BUCKET_COUNT = 10;
	private const MINIMAL_FIX_TIME = 0.1;
	private const BAN_DURATION = 300;
	private const BATCH_TIME_WEIGHT = 0.5;
	private const BAN_KEY_PREFIX = 'ban';
	private const TIME_LOGGER_ID = 'rest.LoadLimiter.time'; // enabled only by the "loggers" section of .settings.php
	private const LIMITED_ENTITY_TYPES = [
		APAuth\Auth::AUTH_TYPE,
		OAuth\Auth::AUTH_TYPE,
	];

	private array $timeStart = [];
	private array $timeFinish = [];
	private ?array $window = null;
	private int $numBucket = 0;
	private bool $isFlushRegistered = false;

	public function __construct(
		private readonly ?object $resource,
		private readonly bool $isActive,
		private readonly int $limitTime,
		private readonly string $domain,
		private readonly ?string $entityType,
		private readonly ?string $entity,
		private readonly ?string $method,
	)
	{
	}

	public function check(): void
	{
		if ($this->isBanned())
		{
			throw new RateLimitException($this->getRetryAfter());
		}
	}

	public function start(): void
	{
		if (!$this->isLimited())
		{
			return;
		}

		$this->timeStart[] = microtime(true);

		if (!$this->isFlushRegistered)
		{
			$this->isFlushRegistered = true;
			Application::getInstance()->addBackgroundJob($this->flush(...));
		}
	}

	public function catchException(\Throwable $e): \Throwable
	{
		return $e;
	}

	public function finish(): void
	{
		if ($this->isLimited() && !empty($this->timeStart))
		{
			$this->timeFinish[] = microtime(true);
		}
	}

	public function getDebugInfo(): array
	{
		if (!$this->isActive)
		{
			return [];
		}

		$reset = $this->getResetTime();

		return ($reset ? ['operating_reset_at' => $reset] : []) + ['operating' => $this->getRestTime()];
	}

	protected function getTimeLogger(): ?LoggerInterface
	{
		static $isInitialized = false;
		static $logger = null;

		if (!$isInitialized)
		{
			$isInitialized = true;
			$logger = (new LoggerFactory(alwaysReturnLogger: false))->createById(
				self::TIME_LOGGER_ID,
				isCheckEnabledFromRegistry: false,
				returnDefaultLoggerIfNotExists: false,
			);
		}

		return $logger;
	}

	protected function getNumBucket(): int
	{
		$this->numBucket = $this->numBucket ?: intdiv(time(), self::BUCKET_SIZE);

		return $this->numBucket;
	}

	private function getRetryAfter(): int
	{
		$banTtl = $this->isActive
			? (int)$this->resource?->ttl($this->getKey(prefix: self::BAN_KEY_PREFIX))
			: 0
		;

		return $banTtl > 0 ? $banTtl : self::BAN_DURATION;
	}

	private function flush(): void
	{
		$duration = array_sum($this->durations());
		$time = $duration * $this->getWeight();
		$operatingTime = $time > self::MINIMAL_FIX_TIME ? $time : 0.0;
		$hits = count($this->timeStart);
		$this->timeStart = [];
		$this->timeFinish = [];
		$this->window = null;

		if ($this->resource && $operatingTime > 0)
		{
			$this->saveTime($operatingTime);
		}

		$this->logTime($hits, $duration, $operatingTime);
	}

	private function saveTime(float $time): void
	{
		$key = $this->getKey($this->getNumBucket());

		// The total equals the written time only for a just created bucket: the expiration is set once,
		// and it depends on the bucket number only, so concurrent writers can not disagree on it.
		$total = (float)$this->resource->incrByFloat($key, $time);
		if (abs($total - $time) < 1e-6)
		{
			$this->resource->expireAt($key, $this->getBucketExpireAt($this->getNumBucket()));
		}
	}

	private function logTime(int $hits, float $duration, float $operatingTime): void
	{
		// A broken logger must not affect the limiter accounting.
		try
		{
			$this->getTimeLogger()?->info(
				'{domain} {entityType}:{entity} {method} hits {hits}, duration {duration}, operating time {operatingTime}',
				[
					'domain' => $this->domain,
					'entityType' => $this->entityType,
					'entity' => $this->entity,
					'method' => $this->method,
					'hits' => $hits,
					'duration' => $duration,
					'operatingTime' => $operatingTime,
				]
			);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	private function getResetTime(): ?int
	{
		if (!$this->resource)
		{
			return null;
		}

		$window = $this->readWindow();

		// While the ban is active the buckets are already cleared, so report the ban
		// expiration as the reset time instead of falling back to null.
		if ($window['isBanned'])
		{
			$banTtl = (int)$this->resource->ttl($this->getKey(prefix: self::BAN_KEY_PREFIX));
			if ($banTtl > 0)
			{
				return time() + $banTtl;
			}
		}

		foreach ($window['times'] as $bucketNum => $time)
		{
			if ($time > 0)
			{
				return $this->getBucketExpireAt($bucketNum);
			}
		}

		return empty($this->timeStart) ? null : $this->getBucketExpireAt($this->getNumBucket());
	}

	private function getRestTime(): float
	{
		return $this->sumTime($this->resource ? $this->readWindow()['times'] : []);
	}

	private function sumTime(array $windowTimes): float
	{
		$registeredTimes = array_filter(
			array_map(fn(float $duration): float => $duration * $this->getWeight(), $this->durations()),
			static fn(float $time): bool => $time > self::MINIMAL_FIX_TIME,
		);

		return array_sum($windowTimes) + array_sum($registeredTimes);
	}

	private function isBanned(): bool
	{
		if (!$this->isLimited())
		{
			return false;
		}

		$window = $this->resource ? $this->readWindow() : null;
		if ($window && $window['isBanned'])
		{
			return true;
		}

		$totalTime = $this->sumTime($window['times'] ?? []);
		if ($totalTime <= $this->limitTime)
		{
			return false;
		}

		// Set the ban before clearing the buckets so a concurrent request can never observe
		// an empty window with no active ban and slip through.
		if ($this->resource?->setEx($this->getKey(prefix: self::BAN_KEY_PREFIX), self::BAN_DURATION, '1'))
		{
			$this->resource->del($this->bucketKeys($this->getKey()));
			$this->window = ['times' => [], 'isBanned' => true];
		}

		if (function_exists('saveRestStat'))
		{
			saveRestStat($this->domain, $this->entityType, $this->entity, $this->method, $totalTime);
		}

		return true;
	}

	/**
	 * Reads the window buckets and the ban flag by a single MGET, so a concurrent request that sets
	 * the ban and then clears the buckets can never be observed half-way.
	 *
	 * @return array{times: array<int, float>, isBanned: bool} bucket times keyed by bucket number, oldest first
	 */
	private function readWindow(): array
	{
		if ($this->window !== null)
		{
			return $this->window;
		}

		$bucketNums = range($this->getNumBucket() - self::BUCKET_COUNT + 1, $this->getNumBucket());
		$key = $this->getKey();
		$keys = array_map(static fn(int $bucketNum): string => $key . $bucketNum, $bucketNums);
		$keys[] = $this->getKey(prefix: self::BAN_KEY_PREFIX);

		$values = $this->rawMultiGet($keys);
		$banValue = array_pop($values);

		return $this->window = [
			'times' => array_combine($bucketNums, array_map('floatval', $values)),
			'isBanned' => $banValue !== false && $banValue !== null,
		];
	}

	private function getBucketExpireAt(int $bucketNum): int
	{
		return ($bucketNum + self::BUCKET_COUNT) * self::BUCKET_SIZE;
	}

	private function isLimited(): bool
	{
		return $this->isActive && in_array($this->entityType, self::LIMITED_ENTITY_TYPES, true);
	}

	private function getKey(?int $bucketNum = null, string $prefix = ''): string
	{
		return $this->domain . '|v' . self::VERSION . '|' . ($prefix !== '' ? $prefix . '|' : '')
			. '{' . sha1($this->entityType . '|' . $this->entity . '|' . $this->method) . '}|' . $bucketNum
		;
	}

	private function bucketKeys(string $key): array
	{
		return array_map(
			fn(int $shift): string => $key . ($this->getNumBucket() - $shift),
			range(0, self::BUCKET_COUNT - 1),
		);
	}

	private function getWeight(): float
	{
		return $this->method === Client::METHOD_BATCH ? self::BATCH_TIME_WEIGHT : 1.0;
	}

	private function durations(): array
	{
		return array_map(
			static fn(float $start, float $finish): float => $finish - $start,
			array_intersect_key($this->timeStart, $this->timeFinish),
			$this->timeFinish,
		);
	}

	private function rawMultiGet(array $keys): array
	{
		if (empty($keys))
		{
			return [];
		}

		$values = $this->resource instanceof \RedisCluster
			? $this->resource->rawCommand($keys[0], 'MGET', ...$keys)
			: $this->resource->rawCommand('MGET', ...$keys)
		;

		return is_array($values) ? $values : array_fill(0, count($keys), false);
	}
}
