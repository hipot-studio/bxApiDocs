<?php

namespace Bitrix\BIConnector\Internal\Services\SqlLab;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

class SqlLabRestLimiter
{
	public const FREQUENCY_WINDOW_SECONDS = 60;
	public const FREQUENCY_LOCK_TIMEOUT_SECONDS = 2;
	public const CONCURRENCY_REFUSAL_TTL_SECONDS = 2;
	public const CLEANUP_INTERVAL_SECONDS = 3600;
	public const RETENTION_SECONDS = 86400;

	public const FREQUENCY_LOCK_PREFIX = 'biconnector_sqllab_freq_';
	public const CONCURRENCY_LOCK_PREFIX = 'biconnector_sqllab_conc_';

	private const FREQUENCY_QUOTAS = [
		'portal' => 30,
		'user' => 10,
		'caller' => 20,
	];
	private const CONCURRENCY_QUOTAS = [
		'portal' => 3,
		'user' => 1,
		'caller' => 2,
	];

	private readonly SqlLabLockManager $lockManager;
	private readonly SqlLabRestRateLimitStorage $storage;
	private readonly SqlLabClock $clock;
	private readonly SqlLabRestRefusalCache $refusalCache;

	public function __construct(
		?SqlLabLockManager $lockManager = null,
		?SqlLabRestRateLimitStorage $storage = null,
		?SqlLabClock $clock = null,
		?SqlLabRestRefusalCache $refusalCache = null,
	)
	{
		$this->lockManager = $lockManager ?? new SqlLabLockManager();
		$this->clock = $clock ?? new SqlLabClock();
		$this->storage = $storage ?? new SqlLabRestRateLimitStorage($this->lockManager, $this->clock);
		$this->refusalCache = $refusalCache ?? new SqlLabRestRefusalCache();
	}

	public function acquire(int $userId, string $callerKey): Result
	{
		if ($userId <= 0 || $callerKey === '')
		{
			return $this->unavailableResult();
		}

		$timestamp = $this->clock->now();
		$windowStart = intdiv($timestamp, self::FREQUENCY_WINDOW_SECONDS) * self::FREQUENCY_WINDOW_SECONDS;
		$dimensions = $this->dimensions($userId, $callerKey);

		// A quota spent in this window cannot come back before the window does, so a refusal known
		// from an earlier call is answered from the cache: a caller looping on retries costs no
		// locks, no transaction and no query to the master database.
		$rememberedRefusal = $this->rememberedRefusal($dimensions, $windowStart);
		if ($rememberedRefusal !== null)
		{
			return $this->rateLimitResult($rememberedRefusal);
		}

		// A slot is freed as soon as the call holding it ends, so a busy-slots refusal is trusted for
		// a bucket of seconds instead of the whole window: long enough to answer a client looping on
		// retries without touching the master database, short enough not to hold back a call that a
		// freed slot already allows.
		$rememberedBusySlots = $this->rememberedBusySlots($dimensions, $timestamp);
		if ($rememberedBusySlots !== null)
		{
			return $this->rateLimitResult($rememberedBusySlots);
		}

		// Concurrency slots are taken first: a request refused here must not spend a unit of the
		// frequency quota, otherwise a client sitting at its parallelism ceiling burns the whole
		// minute window on refusals. Nothing of the storage is touched before them either, so a
		// refused request costs neither the removal of stale rows nor a counter.
		$concurrencyLocks = [];
		try
		{
			foreach ($dimensions as $dimension)
			{
				$acquired = false;
				for ($slot = 0; $slot < $dimension['concurrencyQuota']; $slot++)
				{
					$lock = $this->concurrencyLockName($dimension, $slot);
					if ($this->lockManager->acquire($lock))
					{
						$concurrencyLocks[] = $lock;
						$acquired = true;

						break;
					}
				}

				if (!$acquired)
				{
					$this->releaseLocks($concurrencyLocks);
					$this->rememberBusySlots($dimension['key'], $timestamp);

					return $this->rateLimitResult($dimension['type']);
				}
			}
		}
		catch (\Throwable)
		{
			$this->releaseLocks($concurrencyLocks);

			return $this->unavailableResult();
		}

		try
		{
			$this->storage->cleanup($timestamp, self::CLEANUP_INTERVAL_SECONDS, self::RETENTION_SECONDS);
		}
		catch (\Throwable)
		{
			$this->releaseLocks($concurrencyLocks);

			return $this->unavailableResult();
		}

		$frequencyLocks = [];
		try
		{
			foreach ($dimensions as $dimension)
			{
				$lock = $this->frequencyLockName($dimension);

				// Losing the race for a counter lock says nothing about the quota, so a request that
				// did not get it within the timeout is refused as unavailable, not as rate limited.
				if (!$this->lockManager->acquire($lock, self::FREQUENCY_LOCK_TIMEOUT_SECONDS))
				{
					$this->releaseLocks($frequencyLocks);
					$this->releaseLocks($concurrencyLocks);

					return $this->unavailableResult();
				}
				$frequencyLocks[] = $lock;
			}

			$frequencyResult = $this->storage->consume($dimensions, $windowStart);
		}
		catch (\Throwable)
		{
			$this->releaseLocks($frequencyLocks);
			$this->releaseLocks($concurrencyLocks);

			return $this->unavailableResult();
		}

		$this->releaseLocks($frequencyLocks);
		if ($frequencyResult !== true)
		{
			$this->releaseLocks($concurrencyLocks);
			$refusedType = is_string($frequencyResult) ? $frequencyResult : null;
			if ($refusedType !== null)
			{
				$this->rememberRefusal($dimensions, $refusedType, $windowStart);
			}

			return $this->rateLimitResult($refusedType);
		}

		$result = new Result();
		$result->setData([
			'lease' => new SqlLabRestLease($concurrencyLocks, $this->lockManager),
		]);

		return $result;
	}

	/**
	 * A cache failure must not refuse a call that the quotas allow, so it is answered as if nothing
	 * was remembered: the counters in the table stay the only source of truth.
	 */
	private function rememberedRefusal(array $dimensions, int $windowStart): ?string
	{
		try
		{
			return $this->refusalCache->findRefusal($dimensions, $windowStart, self::FREQUENCY_WINDOW_SECONDS);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * A cache failure must not refuse a call that the slots allow, so it is answered as if nothing
	 * was remembered: the named locks stay the only source of truth.
	 */
	private function rememberedBusySlots(array $dimensions, int $timestamp): ?string
	{
		try
		{
			return $this->refusalCache->findBusySlots(
				$dimensions,
				$this->busySlotsBucket($timestamp),
				self::CONCURRENCY_REFUSAL_TTL_SECONDS,
			);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function rememberBusySlots(string $dimensionKey, int $timestamp): void
	{
		try
		{
			$this->refusalCache->rememberBusySlots(
				$dimensionKey,
				$this->busySlotsBucket($timestamp),
				self::CONCURRENCY_REFUSAL_TTL_SECONDS,
			);
		}
		catch (\Throwable)
		{
		}
	}

	private function busySlotsBucket(int $timestamp): int
	{
		return intdiv($timestamp, self::CONCURRENCY_REFUSAL_TTL_SECONDS) * self::CONCURRENCY_REFUSAL_TTL_SECONDS;
	}

	private function rememberRefusal(array $dimensions, string $refusedType, int $windowStart): void
	{
		foreach ($dimensions as $dimension)
		{
			if ($dimension['type'] !== $refusedType)
			{
				continue;
			}

			try
			{
				$this->refusalCache->remember($dimension['key'], $windowStart, self::FREQUENCY_WINDOW_SECONDS);
			}
			catch (\Throwable)
			{
			}

			return;
		}
	}

	private function dimensions(int $userId, string $callerKey): array
	{
		$keys = [
			'portal' => 'portal',
			'user' => 'user:' . $userId,
			'caller' => 'caller:' . hash('sha256', $callerKey),
		];
		$dimensions = [];
		foreach ($keys as $type => $key)
		{
			$dimensions[] = [
				'type' => $type,
				'key' => $key,
				'frequencyQuota' => self::FREQUENCY_QUOTAS[$type],
				'concurrencyQuota' => self::CONCURRENCY_QUOTAS[$type],
			];
		}

		return $dimensions;
	}

	private function frequencyLockName(array $dimension): string
	{
		return self::FREQUENCY_LOCK_PREFIX
			. $dimension['type']
			. '_'
			. substr(hash('sha256', $dimension['key']), 0, 16);
	}

	private function concurrencyLockName(array $dimension, int $slot): string
	{
		return self::CONCURRENCY_LOCK_PREFIX
			. $dimension['type']
			. '_'
			. $slot
			. '_'
			. substr(hash('sha256', $dimension['key']), 0, 16);
	}

	private function releaseLocks(array $locks): void
	{
		foreach (array_reverse($locks) as $lock)
		{
			try
			{
				$this->lockManager->release($lock);
			}
			catch (\Throwable)
			{
			}
		}
	}

	private function rateLimitResult(?string $dimension): Result
	{
		$result = new Result();
		$result->setData(['limiterDimension' => $dimension]);
		$result->addError(new Error('SQL Lab request limit exceeded', 'SQLLAB_RATE_LIMIT_EXCEEDED'));

		return $result;
	}

	private function unavailableResult(): Result
	{
		$result = new Result();
		$result->addError(new Error('SQL Lab is temporarily unavailable', 'SQLLAB_UNAVAILABLE'));

		return $result;
	}
}
