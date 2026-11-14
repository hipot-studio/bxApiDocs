<?php

namespace Bitrix\Call\Agent\Userlog;

use Bitrix\Call\Counter;
use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\Call\Model\CallUserLogIndexTable;
use Bitrix\Call\Model\CallUserLogCountersTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

class CallUserLogCleaner
{
	// Inline defaults for the cleanup options; the dev options page mirrors these (see common.php).
	public const DEFAULT_PERIOD_DAYS = 90;
	public const DEFAULT_BATCH_SIZE = 1000;
	public const DEFAULT_TIME_LIMIT_SEC = 25;

	public static function run(): string
	{
		// Retention window and batching are configurable via module options; class constants provide the defaults.
		$periodDays = max((int)Option::get('call', 'call_userlog_cleanup_period_days', self::DEFAULT_PERIOD_DAYS), 1);
		$batchSize = max((int)Option::get('call', 'call_userlog_cleanup_batch_size', self::DEFAULT_BATCH_SIZE), 1);
		$timeLimit = max((int)Option::get('call', 'call_userlog_cleanup_time_limit_sec', self::DEFAULT_TIME_LIMIT_SEC), 1);

		$expireDate = new DateTime();
		$expireDate->add("-{$periodDays} days");

		// On CLI the request-level time limit does not apply, so the loop drains the whole backlog.
		$startTime = defined('START_EXEC_TIME') ? START_EXEC_TIME : microtime(true);
		$isCli = (php_sapi_name() === 'cli');

		while (true)
		{
			// Stop once the time budget is spent; the next agent run continues from here.
			if (!$isCli && (microtime(true) - $startTime) >= $timeLimit)
			{
				break;
			}

			// Snapshot a bounded batch of expired ids, then delete strictly by that snapshot.
			$ids = [];
			$rows = CallUserLogTable::query()
				->setSelect(['ID'])
				->where('STATUS_TIME', '<', $expireDate)
				->setLimit($batchSize)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$ids[] = (int)$row['ID'];
			}

			if (empty($ids))
			{
				break;
			}

			$owners = self::deleteByIds($ids);
			self::dropCachedCounters($owners);

			// Best-effort badge resync for the owners of this batch, within the shared time budget.
			self::resyncUsers($owners, $startTime, $isCli, $timeLimit);

			// Fewer rows than requested means the backlog is exhausted.
			if (count($ids) < $batchSize)
			{
				break;
			}
		}

		// Secondary cleanup within the remaining time budget; the main drain above keeps priority.
		self::sweepOrphans(CallUserLogIndexTable::class, $batchSize, $startTime, $isCli, $timeLimit);
		self::sweepOrphans(CallUserLogCountersTable::class, $batchSize, $startTime, $isCli, $timeLimit);

		return static::getAgentName();
	}

	/**
	 * Deletes userlog rows together with their index and counter rows for the given id snapshot.
	 * Children (index, counters) go first and the parent row last, so an interrupted run keeps the
	 * still-expired parent rows: the next run re-selects them and repeats the cascade idempotently.
	 *
	 * Returns the distinct owners of the removed counter rows so the caller can drop their cached
	 * counters and best-effort resync their badges; a resync dropped on a crash self-heals on the
	 * owner's next counter change.
	 *
	 * @param int[] $ids Non-empty list of userlog ids.
	 * @return int[] Distinct USER_ID owners of the removed counter rows.
	 */
	private static function deleteByIds(array $ids): array
	{
		// Collect the owners before deleting: once the counter rows are gone this is the only trace
		// that these badges need a resync.
		$users = [];
		$rows = CallUserLogCountersTable::query()
			->setSelect(['USER_ID'])
			->whereIn('USERLOG_ID', $ids)
			->setGroup(['USER_ID'])
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$users[] = (int)$row['USER_ID'];
		}

		CallUserLogIndexTable::deleteByFilter(['@USERLOG_ID' => $ids]);
		CallUserLogCountersTable::deleteByFilter(['@USERLOG_ID' => $ids]);
		CallUserLogTable::deleteByFilter(['@ID' => $ids]);

		return $users;
	}

	/**
	 * Drops the cached missed counter of every given owner, regardless of the time budget.
	 *
	 * The cached number may still count the rows just deleted, and resyncUsers() can skip an owner; that
	 * owner then recounts on the next read instead of keeping the old number for up to a day. Only the
	 * owners: dropping the whole cache directory would make every user of the portal recount.
	 *
	 * @param int[] $userIds
	 */
	private static function dropCachedCounters(array $userIds): void
	{
		foreach ($userIds as $userId)
		{
			Counter::clearCache((int)$userId);
		}
	}

	/**
	 * Best-effort badge resync for the given owners within the shared time budget. Owners skipped by
	 * the budget, or whose per-user lock is busy (resync returns null), are simply dropped: the badge
	 * self-heals on the owner's next counter change, and their cached counter is already dropped.
	 *
	 * @param int[] $userIds
	 */
	private static function resyncUsers(array $userIds, float $startTime, bool $isCli, int $timeLimit): void
	{
		foreach ($userIds as $userId)
		{
			if (!$isCli && (microtime(true) - $startTime) >= $timeLimit)
			{
				break;
			}

			Counter::resync((int)$userId);
		}
	}

	/**
	 * Removes orphan child rows in bounded batches — rows whose USERLOG_ID no longer exists in
	 * b_call_userlog. Orphans come from historical data and from other delete paths that break
	 * between removing the parent and its children (e.g. CallLogService::deleteEntry failing
	 * mid-cascade). An employee's missed counter includes orphan counter rows (it filters by USER_ID
	 * without the parent; a guest's count joins the parent and never sees them), so the counters sweep
	 * drops the cached counters of the owners it removes rows of and resyncs their badges.
	 *
	 * @param class-string<DataManager> $entityClass Child table to sweep (index or counters).
	 * @return int Number of distinct USERLOG_ID values swept.
	 */
	private static function sweepOrphans(string $entityClass, int $batchSize, float $startTime, bool $isCli, int $timeLimit): int
	{
		// Only the counters table carries USER_ID; drop owners' caches and resync their badges just for that sweep.
		$collectUsers = ($entityClass === CallUserLogCountersTable::class);

		$swept = 0;
		while (true)
		{
			// Respect the remaining budget of this run before touching another batch.
			if (!$isCli && (microtime(true) - $startTime) >= $timeLimit)
			{
				break;
			}

			// LEFT-join to the parent table; a NULL parent id marks an orphan. Grouping and
			// limiting by USERLOG_ID keeps the batch a set of whole ids, so the exhaustion
			// check compares like with like and no id is ever half-deleted at the boundary.
			$ids = [];
			$rows = $entityClass::query()
				->registerRuntimeField(
					new Reference(
						'PARENT',
						CallUserLogTable::class,
						Join::on('this.USERLOG_ID', 'ref.ID'),
						['join_type' => Join::TYPE_LEFT],
					)
				)
				->setSelect(['USERLOG_ID'])
				->whereNull('PARENT.ID')
				->setGroup(['USERLOG_ID'])
				->setLimit($batchSize)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$ids[] = (int)$row['USERLOG_ID'];
			}

			if (empty($ids))
			{
				break;
			}

			// Collect every owner of the batch before deleting: the counters table may hold
			// several USER_IDs per USERLOG_ID (group calls) and we delete whole ids at once,
			// so each of those users needs a badge resync afterwards.
			$users = [];
			if ($collectUsers)
			{
				$userRows = $entityClass::query()
					->setSelect(['USER_ID'])
					->whereIn('USERLOG_ID', $ids)
					->setGroup(['USER_ID'])
					->fetchAll()
				;
				foreach ($userRows as $userRow)
				{
					$users[] = (int)$userRow['USER_ID'];
				}
			}

			$entityClass::deleteByFilter(['@USERLOG_ID' => $ids]);
			$swept += count($ids);

			if ($collectUsers)
			{
				self::dropCachedCounters($users);

				// Best-effort badge resync for the owners of this batch, within the shared time budget.
				self::resyncUsers($users, $startTime, $isCli, $timeLimit);
			}

			// Fewer rows than requested means this table has no more orphans.
			if (count($ids) < $batchSize)
			{
				break;
			}
		}

		return $swept;
	}

	public static function getAgentName(): string
	{
		return static::class . '::run();';
	}
}
