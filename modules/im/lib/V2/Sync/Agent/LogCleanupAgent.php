<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Sync\Agent;

use Bitrix\Im\Model\LogTable;
use Bitrix\Im\V2\Sync\LogBoundary;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Type\DateTime;

/**
 * Permanent retention agent that drains rows older than the TTL from b_im_log.
 *
 * Self-reschedules on every run (retention is continuous), so it NEVER returns
 * '' - an empty return would self-delete the agent and stop retention forever.
 * When the toggle is off or another run holds the lock, it stays registered and
 * simply does no work this tick.
 */
class LogCleanupAgent
{
	protected const OPTION_ENABLED = 'log_cleanup_enabled';
	protected const OPTION_TTL_DAYS = 'log_cleanup_ttl_days';

	protected const DEFAULT_ENABLED = 'N';
	protected const DEFAULT_TTL_DAYS = 365;
	protected const MAX_TTL_DAYS = 36500;
	protected const DEFAULT_PORTION = 300;
	protected const DEFAULT_TIME_BUDGET = 20;

	protected const LOCK_NAME = LogBoundary::LOCK_NAME;

	/**
	 * @return string Always the self-call string for rescheduling; never ''.
	 */
	public static function cleanAgent(): string
	{
		$selfCall = self::getAgentName();

		if (!self::isEnabled())
		{
			return $selfCall;
		}

		$ttlDays = self::getTtlDays();

		// cutoff is computed ONCE and passed into SQL as a constant literal.
		$cutoff = new DateTime();
		$cutoff->add("-{$ttlDays} days");

		$connection = Application::getConnection();
		$pool = Application::getInstance()->getConnectionPool();

		// Advisory locks are session-bound. Connection::lock() pins only its own
		// GET_LOCK to the master and then drops master-only, so without an explicit
		// span the portion SELECT (MySQL) or the bx_release_lock SELECT (PostgreSQL)
		// could be routed to a read-replica - a different session that never holds
		// this lock. Pin the whole lock/drain/unlock span to the master connection.
		$pool->useMasterOnly(true);
		try
		{
			if (!$connection->lock(self::LOCK_NAME, 0))
			{
				// Another run is already draining - stand down, stay registered.
				return $selfCall;
			}

			try
			{
				// One-time boundary raise: the first non-empty portion raises
				// log_incomplete_before to cutoff before its DELETE runs; later
				// portions in the same run are no-ops.
				$boundaryRaised = false;
				$beforeDelete = static function () use (&$boundaryRaised, $cutoff): void {
					if ($boundaryRaised)
					{
						return;
					}

					LogBoundary::raiseTo($cutoff->getTimestamp());
					$boundaryRaised = true;
				};

				$deadline = time() + self::DEFAULT_TIME_BUDGET;
				self::runDrain(
					static fn(): int => static::deletePortion($cutoff, self::DEFAULT_PORTION, $beforeDelete),
					static fn(): int => time(),
					$deadline
				);
			}
			finally
			{
				$connection->unlock(self::LOCK_NAME);
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		return $selfCall;
	}

	/**
	 * Drains portions until the time budget is spent or the tail is empty.
	 *
	 * @param callable $deletePortion Returns the number of rows deleted in one portion.
	 * @param callable $now           Returns the current timestamp (seconds).
	 * @param int      $deadline      Absolute timestamp (seconds) to stop at.
	 * @return int Total rows deleted across all portions.
	 */
	protected static function runDrain(callable $deletePortion, callable $now, int $deadline): int
	{
		$total = 0;

		while ($now() < $deadline)
		{
			$affected = (int)$deletePortion();
			$total += $affected;

			if ($affected === 0)
			{
				break;
			}
		}

		return $total;
	}

	/**
	 * Deletes one portion of expired rows. The cursor is implicit (deleted
	 * rows leave the index), and $beforeDelete runs once, right before the
	 * first real DELETE. On failure the exception propagates; no retry or sleep.
	 *
	 * @return int Number of rows removed by this portion.
	 * @throws SqlQueryException
	 * @throws \Bitrix\Im\V2\Sync\LogBoundaryException
	 */
	protected static function deletePortion(DateTime $cutoff, int $portion, callable $beforeDelete): int
	{
		$ids = array_column(
			LogTable::query()
				->setSelect(['ID'])
				->where('DATE_CREATE', '<=', $cutoff)
				->setOrder(['DATE_CREATE' => 'ASC', 'ID' => 'ASC'])
				->setLimit($portion)
				->fetchAll(),
			'ID'
		);

		if (!$ids)
		{
			return 0;
		}

		$beforeDelete();

		return static::deleteIds($ids, $cutoff);
	}

	/**
	 * @param int[] $ids
	 * @throws SqlQueryException
	 */
	protected static function deleteIds(array $ids, DateTime $cutoff): int
	{
		LogTable::deleteByFilter([
			'@ID' => $ids,
			'<=DATE_CREATE' => $cutoff,
		]);

		return (int)Application::getConnection()->getAffectedRowsCount();
	}

	protected static function isEnabled(): bool
	{
		return Option::get('im', self::OPTION_ENABLED, self::DEFAULT_ENABLED) === 'Y';
	}

	protected static function getTtlDays(): int
	{
		$ttlDays = self::normalizePositiveInt(
			Option::get('im', self::OPTION_TTL_DAYS, self::DEFAULT_TTL_DAYS),
			self::DEFAULT_TTL_DAYS
		);

		// Cap retention: an absurd option value underflows the DATE_CREATE cutoff
		// (or throws on strict DateInterval specs) and would only error the agent.
		return min($ttlDays, self::MAX_TTL_DAYS);
	}

	/**
	 * Coerces a raw option value to a positive int, falling back to the default
	 * on empty/non-positive input (unset, 0 or negative).
	 *
	 * @param mixed $raw
	 */
	protected static function normalizePositiveInt($raw, int $default): int
	{
		$value = filter_var($raw, FILTER_VALIDATE_INT);

		return ($value !== false && $value >= 1) ? $value : $default;
	}

	protected static function getAgentName(): string
	{
		// Must EXACTLY match the registered agent NAME (agents.php / updater) with
		// no leading backslash: the core rewrites b_agent.NAME with this return
		// value on every run, so any drift would break the updater's idempotent guard.
		return 'Bitrix\\Im\\V2\\Sync\\Agent\\LogCleanupAgent::cleanAgent();';
	}
}
