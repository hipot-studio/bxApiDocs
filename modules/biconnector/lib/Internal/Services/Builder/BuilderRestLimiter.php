<?php

namespace Bitrix\BIConnector\Internal\Services\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabClock;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabLockManager;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabRestLease;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabRestRateLimitStorage;
use Bitrix\BIConnector\Internal\Services\SqlLab\SqlLabRestRefusalCache;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Request quota of the builder REST methods, built the way SqlLabRestLimiter is: the portal, the user
 * and the caller each have a frequency quota of a 60 second window and a ceiling of parallel calls,
 * read and write methods have quotas of their own. Counters, locks and the refusal cache are those of
 * SQL Lab; the keys of the builder never meet the keys of SQL Lab.
 *
 * The quota is spent in two steps: first the user and the caller, then the portal, and between them the
 * caller is asked whether BI Builder is available to them at all. A caller without access to BI runs
 * into a quota of their own and leaves the shared quota and slots of the portal to the others. A portal
 * step that refuses gives the units of the first step back, so a refused call costs the caller nothing.
 *
 * Every refusal is FailureCode::RateLimit with retryAfter, including a failure of the storage: the
 * client is told to repeat the call, never that the report is unavailable. A refusal decided by the
 * counters or the slots goes to the journal with its dimension; a repeat answered from the refusal
 * cache does not, so a client looping on retries writes no stream of rows.
 */
final class BuilderRestLimiter implements BuilderQuotaGuard
{
	public const FREQUENCY_WINDOW_SECONDS = 60;
	public const FREQUENCY_LOCK_TIMEOUT_SECONDS = 2;
	public const CONCURRENCY_REFUSAL_TTL_SECONDS = 2;
	public const CLEANUP_INTERVAL_SECONDS = 3600;
	public const RETENTION_SECONDS = 86400;
	public const UNAVAILABLE_RETRY_AFTER_SECONDS = 1;

	public const FREQUENCY_LOCK_PREFIX = 'biconnector_builder_freq_';
	public const CONCURRENCY_LOCK_PREFIX = 'biconnector_builder_conc_';

	public const DIMENSION_KEY_PREFIX = 'builder:';

	/**
	 * The user quota of reads is the limit of the BI tools of MCP (BiToolsRateLimiter::MAX_REQUESTS),
	 * the rest follows the proportion of SQL Lab: the portal three times the user, the caller twice.
	 */
	public const FREQUENCY_QUOTAS = [
		'read' => [
			'portal' => 180,
			'user' => 60,
			'caller' => 120,
		],
		'write' => [
			'portal' => 60,
			'user' => 20,
			'caller' => 40,
		],
	];
	public const CONCURRENCY_QUOTAS = [
		'read' => [
			'portal' => 9,
			'user' => 3,
			'caller' => 6,
		],
		'write' => [
			'portal' => 6,
			'user' => 2,
			'caller' => 4,
		],
	];

	private readonly SqlLabLockManager $lockManager;
	private readonly SqlLabRestRateLimitStorage $storage;
	private readonly SqlLabClock $clock;
	private readonly SqlLabRestRefusalCache $refusalCache;
	private readonly BuilderRestLimiterJournal $journal;
	private readonly BuilderRestQuotaRefund $refund;

	public function __construct(
		?SqlLabLockManager $lockManager = null,
		?SqlLabRestRateLimitStorage $storage = null,
		?SqlLabClock $clock = null,
		?SqlLabRestRefusalCache $refusalCache = null,
		?BuilderRestLimiterJournal $journal = null,
		?BuilderRestQuotaRefund $refund = null,
	)
	{
		$this->lockManager = $lockManager ?? new SqlLabLockManager();
		$this->clock = $clock ?? new SqlLabClock();
		$this->storage = $storage ?? new SqlLabRestRateLimitStorage($this->lockManager, $this->clock);
		$this->refusalCache = $refusalCache ?? new SqlLabRestRefusalCache();
		$this->journal = $journal ?? new BuilderRestLimiterJournal();
		$this->refund = $refund ?? new BuilderRestQuotaRefund();
	}

	/**
	 * @param string $callerKey Empty for a call without an application or webhook behind it (session
	 *        authorization, the internal server of MCP, a deferred batch); such calls are counted in a
	 *        caller bucket of their user, never in the bucket of somebody else's traffic.
	 * @param BuilderAvailabilityCheck $availability Asked between the two steps; its refusal is returned
	 *        as it is, and the units of the first step stay spent.
	 */
	public function acquire(
		int $userId,
		string $callerKey,
		BuilderQuotaClass $class,
		BuilderAvailabilityCheck $availability,
	): Result
	{
		$timestamp = $this->clock->now();
		if ($userId <= 0)
		{
			return $this->unavailableResult($class);
		}

		$windowStart = intdiv($timestamp, self::FREQUENCY_WINDOW_SECONDS) * self::FREQUENCY_WINDOW_SECONDS;
		$dimensions = $this->dimensions($userId, $callerKey, $class);

		// A quota spent in this window cannot come back before the window does, so a refusal known
		// from an earlier call costs no locks, no transaction and no query to the master database.
		// Such an answer spends nothing, so the portal is read from the cache before the first step too.
		$rememberedRefusal = $this->rememberedRefusal($dimensions, $windowStart);
		if ($rememberedRefusal !== null)
		{
			return $this->rateLimitResult($rememberedRefusal, $this->secondsLeftOfWindow($timestamp));
		}

		$rememberedBusySlots = $this->rememberedBusySlots($dimensions, $timestamp);
		if ($rememberedBusySlots !== null)
		{
			return $this->rateLimitResult($rememberedBusySlots, $this->secondsLeftOfBusySlotsBucket($timestamp));
		}

		$ownDimensions = array_values(array_filter(
			$dimensions,
			static fn(array $dimension): bool => $dimension['type'] !== 'portal',
		));
		$portalDimensions = array_values(array_filter(
			$dimensions,
			static fn(array $dimension): bool => $dimension['type'] === 'portal',
		));

		$own = $this->spend($ownDimensions, $class, $timestamp, $windowStart, true);
		if (!$own->isSuccess())
		{
			return $own;
		}
		$ownLocks = $own->getData()['locks'];

		try
		{
			$available = $availability->check($userId);
		}
		catch (\Throwable $exception)
		{
			$this->releaseLocks($ownLocks);

			throw $exception;
		}
		if (!$available->isSuccess())
		{
			$this->releaseLocks($ownLocks);

			return $available;
		}

		$portal = $this->spend($portalDimensions, $class, $timestamp, $windowStart, false);
		if (!$portal->isSuccess())
		{
			$this->refundOwnStep($ownDimensions, $windowStart);
			$this->releaseLocks($ownLocks);

			return $portal;
		}

		return (new Result())->setData([
			'lease' => new SqlLabRestLease([...$ownLocks, ...$portal->getData()['locks']], $this->lockManager),
		]);
	}

	/**
	 * One step of the quota: the slots of its dimensions, then their counters in one transaction.
	 *
	 * @param bool $cleanup The removal of old rows is asked once a call, on its first step.
	 * @return Result Data of a granted step carry the slot locks it holds; a refused step holds none.
	 */
	private function spend(
		array $dimensions,
		BuilderQuotaClass $class,
		int $timestamp,
		int $windowStart,
		bool $cleanup,
	): Result
	{
		// Slots are taken before the counters, so a call refused for parallelism spends no unit of the
		// frequency quota.
		$concurrencyLocks = [];
		try
		{
			foreach ($dimensions as $dimension)
			{
				$lock = $this->acquireSlot($dimension);
				if ($lock === null)
				{
					$this->releaseLocks($concurrencyLocks);
					$this->rememberBusySlots($dimension['key'], $timestamp);

					return $this->journaledRefusal(
						$class,
						$dimension['type'],
						$this->secondsLeftOfBusySlotsBucket($timestamp),
					);
				}
				$concurrencyLocks[] = $lock;
			}
		}
		catch (\Throwable)
		{
			$this->releaseLocks($concurrencyLocks);

			return $this->unavailableResult($class);
		}

		$frequencyLocks = [];
		try
		{
			if ($cleanup)
			{
				$this->storage->cleanup($timestamp, self::CLEANUP_INTERVAL_SECONDS, self::RETENTION_SECONDS);
			}

			foreach ($dimensions as $dimension)
			{
				$lock = $this->frequencyLockName($dimension);
				if (!$this->lockManager->acquire($lock, self::FREQUENCY_LOCK_TIMEOUT_SECONDS))
				{
					$this->releaseLocks($frequencyLocks);
					$this->releaseLocks($concurrencyLocks);

					return $this->unavailableResult($class);
				}
				$frequencyLocks[] = $lock;
			}

			$frequencyResult = $this->storage->consume($dimensions, $windowStart);
		}
		catch (\Throwable)
		{
			$this->releaseLocks($frequencyLocks);
			$this->releaseLocks($concurrencyLocks);

			return $this->unavailableResult($class);
		}

		$this->releaseLocks($frequencyLocks);
		if ($frequencyResult !== true)
		{
			$this->releaseLocks($concurrencyLocks);
			if (!is_string($frequencyResult))
			{
				return $this->unavailableResult($class);
			}
			$this->rememberRefusal($dimensions, $frequencyResult, $windowStart);

			return $this->journaledRefusal($class, $frequencyResult, $this->secondsLeftOfWindow($timestamp));
		}

		return (new Result())->setData(['locks' => $concurrencyLocks]);
	}

	/**
	 * A refund that fails leaves the caller one unit short until the window is over; the call is refused
	 * either way, so the answer does not depend on it.
	 *
	 * A parallel call may have remembered a refusal of the user or the caller while this call held their last
	 * unit; once the unit is back that refusal is no longer true, so it is forgotten and the counters decide.
	 */
	private function refundOwnStep(array $ownDimensions, int $windowStart): void
	{
		$keys = array_column($ownDimensions, 'key');
		try
		{
			$this->refund->refund($keys, $windowStart);
		}
		catch (\Throwable)
		{
			return;
		}

		foreach ($keys as $key)
		{
			try
			{
				$this->refusalCache->forget($key, $windowStart);
			}
			catch (\Throwable)
			{
			}
		}
	}

	/**
	 * Called from the handling of any exception of the call, so it never throws.
	 */
	public function release(Result $acquired): void
	{
		try
		{
			$lease = $acquired->getData()['lease'] ?? null;
			if ($lease instanceof SqlLabRestLease)
			{
				$lease->release();
			}
		}
		catch (\Throwable)
		{
		}
	}

	private function acquireSlot(array $dimension): ?string
	{
		for ($slot = 0; $slot < $dimension['concurrencyQuota']; $slot++)
		{
			$lock = $this->concurrencyLockName($dimension, $slot);
			if ($this->lockManager->acquire($lock))
			{
				return $lock;
			}
		}

		return null;
	}

	/**
	 * A cache failure must not refuse a call that the quotas allow: the counters stay the source of truth.
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
	 * A cache failure must not refuse a call that the slots allow: the named locks stay the source of truth.
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

	private function busySlotsBucket(int $timestamp): int
	{
		return intdiv($timestamp, self::CONCURRENCY_REFUSAL_TTL_SECONDS) * self::CONCURRENCY_REFUSAL_TTL_SECONDS;
	}

	private function secondsLeftOfWindow(int $timestamp): int
	{
		return self::FREQUENCY_WINDOW_SECONDS - $timestamp % self::FREQUENCY_WINDOW_SECONDS;
	}

	private function secondsLeftOfBusySlotsBucket(int $timestamp): int
	{
		return self::CONCURRENCY_REFUSAL_TTL_SECONDS - $timestamp % self::CONCURRENCY_REFUSAL_TTL_SECONDS;
	}

	private function dimensions(int $userId, string $callerKey, BuilderQuotaClass $class): array
	{
		$quotaClass = $this->quotaClassName($class);
		$prefix = self::DIMENSION_KEY_PREFIX . $quotaClass . ':';
		$keys = [
			'portal' => $prefix . 'portal',
			'user' => $prefix . 'user:' . $userId,
			'caller' => $callerKey === ''
				? $prefix . 'caller:none:user:' . $userId
				: $prefix . 'caller:' . hash('sha256', $callerKey),
		];
		$dimensions = [];
		foreach ($keys as $type => $key)
		{
			$dimensions[] = [
				'type' => $type,
				'key' => $key,
				'frequencyQuota' => self::FREQUENCY_QUOTAS[$quotaClass][$type],
				'concurrencyQuota' => self::CONCURRENCY_QUOTAS[$quotaClass][$type],
			];
		}

		return $dimensions;
	}

	private function quotaClassName(BuilderQuotaClass $class): string
	{
		return match ($class)
		{
			BuilderQuotaClass::Read => 'read',
			BuilderQuotaClass::Write => 'write',
		};
	}

	private function frequencyLockName(array $dimension): string
	{
		return self::FREQUENCY_LOCK_PREFIX . substr(hash('sha256', $dimension['key']), 0, 32);
	}

	private function concurrencyLockName(array $dimension, int $slot): string
	{
		return self::CONCURRENCY_LOCK_PREFIX . $slot . '_' . substr(hash('sha256', $dimension['key']), 0, 32);
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

	private function journaledRefusal(BuilderQuotaClass $class, ?string $dimension, int $retryAfter): Result
	{
		$this->journal->refused($this->quotaClassName($class), $dimension, $retryAfter);

		return $this->rateLimitResult($dimension, $retryAfter);
	}

	/**
	 * The quota could not be counted (no user, a lock not taken in time, the storage failed). Such a call
	 * is refused as rate limited too: a repeat after a second is the only advice that is true.
	 */
	private function unavailableResult(BuilderQuotaClass $class): Result
	{
		return $this->journaledRefusal($class, null, self::UNAVAILABLE_RETRY_AFTER_SECONDS);
	}

	private function rateLimitResult(?string $dimension, int $retryAfter): Result
	{
		return (new Result())
			->setData([
				'retryAfter' => $retryAfter,
				'limiterDimension' => $dimension,
			])
			->addError(new Error('Builder request limit exceeded', FailureCode::RateLimit->value))
		;
	}
}
