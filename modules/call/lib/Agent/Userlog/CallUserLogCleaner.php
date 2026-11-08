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

		$deletedTotal = 0;
		while (true)
		{
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

			self::deleteByIds($ids);
			$deletedTotal += count($ids);

			// Fewer rows than requested means the backlog is exhausted.
			if (count($ids) < $batchSize)
			{
				break;
			}

			// Stop once the time budget is spent; the next agent run continues from here.
			if (!$isCli && (microtime(true) - $startTime) >= $timeLimit)
			{
				break;
			}
		}

		// Secondary cleanup within the remaining time budget; the main drain above keeps priority.
		self::sweepOrphans(CallUserLogIndexTable::class, $batchSize, $startTime, $isCli, $timeLimit);
		$sweptCounters = self::sweepOrphans(CallUserLogCountersTable::class, $batchSize, $startTime, $isCli, $timeLimit);

		// Counter::getCount() counts by USER_ID without joining the parent, so both the cascade
		// and the counters orphan sweep shrink user badge values; drop the cached counters once per run.
		if ($deletedTotal > 0 || $sweptCounters > 0)
		{
			Counter::clearCache();
		}

		return static::getAgentName();
	}

	/**
	 * Deletes userlog rows together with their index and counter rows for the given id snapshot.
	 * Children (index, counters) go first and the parent row last, so an interrupted run keeps the
	 * still-expired parent rows: the next run re-selects them and repeats the cascade idempotently.
	 *
	 * @param int[] $ids Non-empty list of userlog ids.
	 */
	private static function deleteByIds(array $ids): void
	{
		CallUserLogIndexTable::deleteByFilter(['@USERLOG_ID' => $ids]);
		CallUserLogCountersTable::deleteByFilter(['@USERLOG_ID' => $ids]);
		CallUserLogTable::deleteByFilter(['@ID' => $ids]);
	}

	/**
	 * Removes orphan child rows in bounded batches — rows whose USERLOG_ID no longer exists in
	 * b_call_userlog. Orphans come from historical data and from other delete paths that break
	 * between removing the parent and its children (e.g. CallLogService::deleteEntry failing
	 * mid-cascade). Counter math includes orphan rows (it filters by USER_ID only), so the caller
	 * must clear the counter cache when the counters sweep reports deletions.
	 *
	 * @param class-string<DataManager> $entityClass Child table to sweep (index or counters).
	 * @return int Number of distinct USERLOG_ID values swept.
	 */
	private static function sweepOrphans(string $entityClass, int $batchSize, float $startTime, bool $isCli, int $timeLimit): int
	{
		$swept = 0;
		while (true)
		{
			// Respect the remaining budget of this run before touching another batch.
			if (!$isCli && (microtime(true) - $startTime) >= $timeLimit)
			{
				break;
			}

			// LEFT-join to the parent table; a NULL parent id marks an orphan. Grouping by
			// USERLOG_ID keeps the snapshot distinct (counters may hold several rows per id).
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

			$entityClass::deleteByFilter(['@USERLOG_ID' => $ids]);
			$swept += count($ids);

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
