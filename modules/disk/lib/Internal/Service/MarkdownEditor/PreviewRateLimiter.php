<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DB\Connection;
use Throwable;

/**
 * Sliding-window limit of the preview of a Markdown text being edited, after {@see
 * \Bitrix\Disk\Internal\Service\DockedUpload\QueueRateLimiter}.
 *
 * A call spends one call and its bytes of every budget it is given: an employee has one budget, a guest of a
 * public link has two - the one of the link and the address it comes from, and the one of the link as a whole.
 * The windows live in the persistent storage, not in the session, so a new session finds the same spent window.
 * A window is aggregated per second, which bounds it by the length of the window instead of by the calls.
 *
 * Either every budget is spent or none: all windows are read and checked before any is written, under the
 * locks of all subjects taken in the order of the subjects, so two calls never wait for each other in a circle.
 * The address of a guest is never stored as is: it only goes into the hash that names the subject.
 *
 * When it cannot account (no lock, storage unavailable) it refuses the call.
 */
final class PreviewRateLimiter
{
	private const STORAGE_KEY_PREFIX = 'disk.md_preview.rate.';
	private const LOCK_KEY_PREFIX = 'disk_md_preview_rate_';
	/** Seconds to wait for a lock: the critical section is a few storage calls long. */
	private const LOCK_TIMEOUT = 1;
	private const EMPTY_CLIENT_IP = '-';

	private const CALLS = 0;
	private const BYTES = 1;

	public function __construct(
		private readonly PersistentStorageInterface $storage,
		private readonly ?Connection $connection = null,
	)
	{
	}

	/**
	 * Books one call and $bytes of every budget.
	 *
	 * @param list<PreviewBudget> $budgets
	 * @return bool whether all the budgets covered the call; false means the call is refused and nothing is spent
	 */
	public function tryConsume(array $budgets, int $bytes, int $windowSeconds): bool
	{
		$budgets = self::normalizeBudgets($budgets);
		if ($budgets === null || $windowSeconds <= 0)
		{
			return false;
		}

		// A call always costs at least one byte, so an empty text is never free.
		$bytes = max(1, $bytes);

		$connection = $this->connection ?? Application::getConnection();
		$takenLocks = [];

		try
		{
			foreach (array_keys($budgets) as $subject)
			{
				$lockName = self::lockName($subject);
				if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
				{
					return false;
				}
				$takenLocks[] = $lockName;
			}

			$now = time();
			$windows = [];
			foreach ($budgets as $subject => $budget)
			{
				$window = $this->readWindow(self::storageKey($subject), $now - $windowSeconds);
				$calls = array_sum(array_column($window, self::CALLS));
				$spentBytes = array_sum(array_column($window, self::BYTES));
				if ($calls + 1 > $budget->callLimit || $spentBytes + $bytes > $budget->byteLimit)
				{
					return false;
				}

				$windows[$subject] = $window;
			}

			foreach ($windows as $subject => $window)
			{
				$window[$now] = [
					self::CALLS => ($window[$now][self::CALLS] ?? 0) + 1,
					self::BYTES => ($window[$now][self::BYTES] ?? 0) + $bytes,
				];
				// The TTL is the window itself: an older record has nothing left to say.
				$this->storage->set(self::storageKey($subject), $window, $windowSeconds);
			}

			return true;
		}
		catch (Throwable)
		{
			return false;
		}
		finally
		{
			foreach (array_reverse($takenLocks) as $lockName)
			{
				try
				{
					$connection->unlock($lockName);
				}
				catch (Throwable)
				{
					// A named lock dies with the connection anyway.
				}
			}
		}
	}

	public static function subjectForUser(int $userId): string
	{
		return 'u' . $userId;
	}

	public static function subjectForGuest(int $linkId, string $clientIp): string
	{
		$clientIp = $clientIp === '' ? self::EMPTY_CLIENT_IP : $clientIp;

		return 'g' . md5("{$linkId}|{$clientIp}");
	}

	public static function subjectForLink(int $linkId): string
	{
		return 'l' . $linkId;
	}

	public static function storageKey(string $subject): string
	{
		return self::STORAGE_KEY_PREFIX . $subject;
	}

	public static function lockName(string $subject): string
	{
		return self::LOCK_KEY_PREFIX . md5($subject);
	}

	/**
	 * Budgets by subject in the order of the subjects; a subject given twice keeps the stricter limits.
	 *
	 * @param list<PreviewBudget> $budgets
	 * @return array<string, PreviewBudget>|null null when a call with these budgets is refused outright
	 */
	private static function normalizeBudgets(array $budgets): ?array
	{
		$bySubject = [];
		foreach ($budgets as $budget)
		{
			if (
				!$budget instanceof PreviewBudget
				|| $budget->subject === ''
				|| $budget->callLimit <= 0
				|| $budget->byteLimit <= 0
			)
			{
				return null;
			}

			$known = $bySubject[$budget->subject] ?? null;
			$bySubject[$budget->subject] = $known === null ? $budget : new PreviewBudget(
				$budget->subject,
				min($known->callLimit, $budget->callLimit),
				min($known->byteLimit, $budget->byteLimit),
			);
		}

		if ($bySubject === [])
		{
			return null;
		}

		ksort($bySubject, SORT_STRING);

		return $bySubject;
	}

	/**
	 * The still-current part of the window: unix second => [calls, bytes]. A value that cannot be read as a
	 * window reads as an empty one - the record belongs to this limiter alone and expires within the window.
	 *
	 * @return array<int, array{0: int, 1: int}>
	 */
	private function readWindow(string $key, int $oldestSecond): array
	{
		$stored = $this->storage->get($key, []);
		if (!is_array($stored))
		{
			return [];
		}

		$window = [];
		foreach ($stored as $second => $spent)
		{
			if ((int)$second <= $oldestSecond || !is_array($spent))
			{
				continue;
			}

			$calls = (int)($spent[self::CALLS] ?? 0);
			$bytes = (int)($spent[self::BYTES] ?? 0);
			if ($calls > 0 || $bytes > 0)
			{
				$window[(int)$second] = [self::CALLS => max(0, $calls), self::BYTES => max(0, $bytes)];
			}
		}

		return $window;
	}
}
