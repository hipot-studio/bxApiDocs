<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\ChartAnalysis;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Diag\LoggerFactory;

/**
 * Per-user sliding window for chart analysis launches: every launch that reaches the chat costs a BitrixGPT
 * request, so a user may start at most MAX_LAUNCHES of them within WINDOW_SECONDS. A refused launch is not
 * counted. The window is kept in the cache, like BiToolsRateLimiter does.
 *
 * A slot is reserved before the chat is written and given back when the write fails. The window is read,
 * checked and written under a named lock of the user, so parallel launches cannot share one free slot;
 * the lock is not held while the chat is written.
 */
class ChartAnalysisLaunchRateLimiter
{
	public const WINDOW_SECONDS = 60;
	public const MAX_LAUNCHES = 5;

	private const CACHE_DIR = '/biconnector/chart_analysis_ratelimit/';
	private const CACHE_KEY_PREFIX = 'chart_analysis_launch_rate_';
	private const LOCK_PREFIX = 'biconnector.chart_analysis_launch_rate.';
	// The lock covers a cache read and write only, so a second launch of the user waits for it briefly.
	private const LOCK_TIMEOUT_SECONDS = 2;
	private const LOGGER_ID = 'biconnector.chart_analysis.launch_rate_limiter';

	private readonly \Closure $clock;
	private readonly \Closure $acquireLock;
	private readonly \Closure $releaseLock;

	/**
	 * @param \Closure(): int|null $clock
	 * @param \Closure(string, int): bool|null $acquireLock
	 * @param \Closure(string): void|null $releaseLock
	 */
	public function __construct(
		?\Closure $clock = null,
		?\Closure $acquireLock = null,
		?\Closure $releaseLock = null,
	)
	{
		$this->clock = $clock ?? static fn(): int => time();
		$this->acquireLock = $acquireLock
			?? static fn(string $lockName, int $timeout): bool =>
				Application::getConnection()->lock($lockName, $timeout)
		;
		$this->releaseLock = $releaseLock
			?? static function(string $lockName): void {
				Application::getConnection()->unlock($lockName);
			};
	}

	/**
	 * Takes a slot of the window when it has room for one.
	 *
	 * @return string|null Id of the reservation for release(); null when the launch is refused.
	 */
	public function reserve(int $userId): ?string
	{
		$reservation = $this->underLock($userId, function(string $key, string $cacheDir): ?string {
			$launches = $this->readWindow($key, $cacheDir);
			if (count($launches) >= self::MAX_LAUNCHES)
			{
				return null;
			}

			$reservation = bin2hex(random_bytes(8));
			$launches[$reservation] = ($this->clock)();
			$this->write($key, $cacheDir, $launches);

			return $reservation;
		});

		return is_string($reservation) ? $reservation : null;
	}

	/**
	 * Gives back the slot of a launch that spent no request. Without the lock the slot stays taken
	 * until the window passes it, which is logged.
	 */
	public function release(int $userId, string $reservation): void
	{
		$outcome = $this->underLock($userId, function(string $key, string $cacheDir) use ($reservation): ?string {
			$launches = $this->readWindow($key, $cacheDir);
			if (array_key_exists($reservation, $launches))
			{
				unset($launches[$reservation]);
				$this->write($key, $cacheDir, $launches);
			}

			return null;
		});

		if ($outcome === false)
		{
			$this->logLostRelease($userId);
		}
	}

	protected function logLostRelease(int $userId): void
	{
		try
		{
			(new LoggerFactory())
				->createDefault()
				?->warning('BI chart analysis: launch slot was not given back, the lock was not taken', [
					'loggerId' => self::LOGGER_ID,
					'userId' => $userId,
				])
			;
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * A lock that cannot be taken refuses the launch: without it the window would not stay exact.
	 *
	 * @param \Closure(string, string): ?string $action
	 * @return string|false|null What the action returned; false when the lock was not taken.
	 */
	private function underLock(int $userId, \Closure $action): string|false|null
	{
		$lockName = self::LOCK_PREFIX . $userId;
		try
		{
			$isLocked = ($this->acquireLock)($lockName, self::LOCK_TIMEOUT_SECONDS);
		}
		catch (\Throwable)
		{
			$isLocked = false;
		}

		if (!$isLocked)
		{
			return false;
		}

		try
		{
			return $action(
				self::CACHE_KEY_PREFIX . $userId,
				self::CACHE_DIR . substr(md5((string)$userId), 2, 2) . '/',
			);
		}
		finally
		{
			try
			{
				($this->releaseLock)($lockName);
			}
			catch (\Throwable)
			{
				// The connection releases the named lock even if an explicit unlock fails.
			}
		}
	}

	/**
	 * @return array<string, int> Launch time by reservation id, only the launches inside the window.
	 */
	private function readWindow(string $key, string $cacheDir): array
	{
		$windowStart = ($this->clock)() - self::WINDOW_SECONDS;

		return array_filter(
			$this->read($key, $cacheDir),
			static fn($timestamp): bool => (int)$timestamp > $windowStart,
		);
	}

	private function read(string $key, string $cacheDir): array
	{
		$cache = Cache::createInstance();
		if ($cache->initCache(self::WINDOW_SECONDS, $key, $cacheDir))
		{
			return (array)$cache->getVars();
		}

		return [];
	}

	private function write(string $key, string $cacheDir, array $launches): void
	{
		$cache = Cache::createInstance();
		$cache->forceRewriting(true);
		if ($cache->startDataCache(self::WINDOW_SECONDS, $key, $cacheDir))
		{
			$cache->endDataCache($launches);
		}
	}
}
