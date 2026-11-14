<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Throwable;

/**
 * Per-user sliding-window budget of the docked upload write surface (Q-6).
 *
 * It is a per-USER limit, not a per-session one: the window lives in the persistent
 * storage under the user id, so a second session - or simply a request that drops the
 * cookie of the general session, which is not the one carrying the authorization -
 * finds the same spent window. Read-filter-write runs inside a per-user connection
 * lock, the device the create ceiling already uses for the same reason.
 *
 * A call spends what it asks the server to do - queue elements enqueued, folder-tree
 * nodes materialized, queue rows changed - so one request for 500 elements is not one
 * request for 1. There are three buckets: the enqueue one, the queue-closing one and the
 * row-mutation one; a call of one surface never spends the budget of another.
 * The window is aggregated per second, which bounds the stored value by the length of
 * the window instead of by the number of calls.
 *
 * When it cannot account (lock or storage unavailable) it REFUSES the call. Letting
 * the flood through unnoticed is exactly what the previous session-bound window did on
 * a portal with a virtual or read-only session, and the storage used here is the very
 * database the queue itself is written to.
 */
class QueueRateLimiter
{
	/** Enqueue budget - create / createFolderTree - spent in queue elements. */
	public const BUCKET_ENQUEUE = 'enqueue';
	/** Queue-closing budget - cancel / dismiss - spent in calls. */
	public const BUCKET_LIFECYCLE = 'lifecycle';
	/** Row-mutation budget - updateItem / updateItems - spent in rows. */
	public const BUCKET_MUTATION = 'mutation';

	private const STORAGE_KEY_PREFIX = 'disk.docked_upload.rate.';
	private const LOCK_KEY_PREFIX = 'disk_docked_rate_';
	/** Seconds to wait for the per-user lock: the critical section is two storage calls long. */
	private const LOCK_TIMEOUT = 1;

	public function __construct(
		private readonly PersistentStorageInterface $storage,
	)
	{
	}

	/**
	 * Books $cost of the $bucket budget of the user.
	 *
	 * @return bool whether the budget covered the call; false means the call is refused
	 */
	public function tryConsume(string $bucket, int $userId, int $cost, int $limit, int $windowSeconds): bool
	{
		if ($userId <= 0 || $limit <= 0 || $windowSeconds <= 0)
		{
			return false;
		}

		// A call always costs at least one slot, so a zero-sized request can never be free.
		$cost = max(1, $cost);

		$connection = Application::getConnection();
		$lockName = self::LOCK_KEY_PREFIX . $bucket . '_' . $userId;
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return false;
		}

		try
		{
			$key = self::storageKey($bucket, $userId);
			$now = time();
			$window = $this->readWindow($key, $now - $windowSeconds);

			if (array_sum($window) + $cost > $limit)
			{
				return false;
			}

			$window[$now] = ($window[$now] ?? 0) + $cost;
			// The TTL is the window itself: a record older than that has nothing left to
			// say, and the kernel storage cleanup collects it without any agent of ours.
			$this->storage->set($key, $window, $windowSeconds);

			return true;
		}
		catch (Throwable)
		{
			return false;
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	public static function storageKey(string $bucket, int $userId): string
	{
		return self::STORAGE_KEY_PREFIX . $bucket . '.' . $userId;
	}

	/**
	 * The still-current part of the window: unix second => cost booked in it. A value the
	 * storage cannot be read as a window reads as an empty one - the record belongs to this
	 * limiter alone and expires within the window anyway.
	 *
	 * @return array<int, int>
	 */
	private function readWindow(string $key, int $oldestSecond): array
	{
		$stored = $this->storage->get($key, []);
		if (!is_array($stored))
		{
			return [];
		}

		$window = [];
		foreach ($stored as $second => $cost)
		{
			if ((int)$second > $oldestSecond && (int)$cost > 0)
			{
				$window[(int)$second] = (int)$cost;
			}
		}

		return $window;
	}
}
