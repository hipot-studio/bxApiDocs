<?php

namespace Bitrix\Voximplant\Agent;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\FileLogger;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\AppTable;
use Bitrix\Voximplant\Model\ExternalLineTable;
use Psr\Log\LoggerInterface;

/**
 * Batch retention cleaner for the external line table (b_voximplant_external_line).
 *
 * Removes rest-app external lines that no living configuration references any more, in bounded
 * batches, within a per-run time budget. Deletion is always by an ID snapshot no larger than the
 * batch size - never an unbounded DELETE - so an interrupted run is safe and the next run resumes
 * idempotently. SIP lines and manually authored settings are protected by the filters below.
 */
class ExternalLineCleaner
{
	// Inline defaults for the cleanup options; the module options mirror these.
	public const DEFAULT_PERIOD_DAYS = 90;
	public const DEFAULT_BATCH_SIZE = 1000;
	public const DEFAULT_TIME_LIMIT_SEC = 25;

	// A candidate must be at least this old before any age-guarded criterion may remove it.
	public const SAFE_MIN_AGE_DAYS = 7;
	// Retention window floor: never shorter than the lifetime of transient (in-flight) calls.
	public const MIN_PERIOD_DAYS = 30;

	private const CHUNK_SIZE = 300;
	private const LOGGER_ID = 'voximplant.externalLineCleaner';

	public static function run(): string
	{
		self::cleanup();

		return static::getAgentName();
	}

	public static function getAgentName(): string
	{
		return static::class . '::run();';
	}

	/**
	 * Runs every criterion once and returns the per-criterion deleted counts. Extracted from run()
	 * so the count of removed rows is observable to tests; run() only needs the agent name back.
	 *
	 * @return array{garbage: int, orphan: int, inactive: int, retention: int}
	 */
	private static function cleanup(): array
	{
		// Retention window is clamped up to MIN_PERIOD_DAYS: a shorter window could delete lines of
		// still-running transient calls. Batch size and time budget only need a floor of 1.
		$periodDays = max((int)Option::get('voximplant', 'external_line_cleanup_period_days', self::DEFAULT_PERIOD_DAYS), self::MIN_PERIOD_DAYS);
		$batchSize = max((int)Option::get('voximplant', 'external_line_cleanup_batch_size', self::DEFAULT_BATCH_SIZE), 1);
		$timeLimit = max((int)Option::get('voximplant', 'external_line_cleanup_time_limit_sec', self::DEFAULT_TIME_LIMIT_SEC), 1);

		$expire = (new DateTime())->add("-{$periodDays} days");
		$minAge = (new DateTime())->add('-' . self::SAFE_MIN_AGE_DAYS . ' days');

		// The budget applies in every SAPI (the standard cron agent runner is CLI too) and counts
		// from the cleaner start, so one run never drains a huge backlog in a single sitting.
		$startTime = microtime(true);
		$budgetExceeded = static function () use ($startTime, $timeLimit): bool
		{
			return (microtime(true) - $startTime) >= $timeLimit;
		};

		$counts = ['garbage' => 0, 'orphan' => 0, 'inactive' => 0, 'retention' => 0];

		// Snapshot the rest app registry once and split the lines' app ids into orphan / inactive /
		// active buckets. Without the rest module every line would look orphaned, so its criteria
		// (orphan, inactive, retention) are skipped entirely and only the garbage criterion runs.
		$orphanIds = $inactiveIds = $activeIds = [];
		$restAvailable = Loader::includeModule('rest');
		if ($restAvailable)
		{
			try
			{
				[$orphanIds, $inactiveIds, $activeIds] = self::classifyLineApps();
			}
			catch (\Throwable $e)
			{
				// A failed registry snapshot must not turn every line into an orphan.
				$restAvailable = false;
				self::getLogger()->error('{date} Voximplant external line cleanup: app registry snapshot failed: ' . $e->getMessage() . "\n");
			}
		}

		// Each drainer accumulates into its counter by reference after every batch, so the rows
		// already deleted before a mid-run failure still reach the cache invalidation below.
		$criteria = [
			'garbage' => static function (int &$deleted) use ($expire, $batchSize, $budgetExceeded): void
			{
				self::drain(self::garbageFilter($expire), $batchSize, $budgetExceeded, $deleted);
			},
		];
		if ($restAvailable)
		{
			$criteria['orphan'] = static function (int &$deleted) use ($orphanIds, $minAge, $batchSize, $budgetExceeded): void
			{
				self::drainByAppIds($orphanIds, static fn(array $chunk): array => self::orphanFilter($chunk, $minAge), $batchSize, $budgetExceeded, $deleted);
			};
			$criteria['inactive'] = static function (int &$deleted) use ($inactiveIds, $minAge, $batchSize, $budgetExceeded): void
			{
				self::drainByAppIds(
					$inactiveIds,
					static fn(array $chunk): array => self::inactiveFilter($chunk, $minAge),
					$batchSize,
					$budgetExceeded,
					$deleted,
					static fn(array $rows): array => self::keepLinesOfStillInactiveApps($rows),
				);
			};
			$criteria['retention'] = static function (int &$deleted) use ($activeIds, $expire, $batchSize, $budgetExceeded): void
			{
				self::drainByAppIds($activeIds, static fn(array $chunk): array => self::retentionFilter($chunk, $expire), $batchSize, $budgetExceeded, $deleted);
			};
		}

		foreach ($criteria as $name => $drainer)
		{
			if ($budgetExceeded())
			{
				break;
			}

			try
			{
				$drainer($counts[$name]);
			}
			catch (\Throwable $e)
			{
				// One failing criterion must not silently swallow the others.
				self::getLogger()->error(sprintf('{date} Voximplant external line cleanup: criterion "%s" failed: %s', $name, $e->getMessage()) . "\n");
			}
		}

		$total = array_sum($counts);
		if ($total > 0)
		{
			// Both cache surfaces of the external lines are invalidated once, at the end of a run
			// that actually deleted something; an empty run leaves the caches untouched.
			ExternalLineTable::getEntity()->cleanCache();
			\CVoxImplantUser::clearCache();

			// The {date} placeholder is filled by the default LogFormatter; FileLogger appends
			// nothing by itself, so the record carries its own timestamp and line break.
			self::getLogger()->info(sprintf(
				'{date} Voximplant external line cleanup finished: garbage=%d, orphan=%d, inactive=%d, retention=%d, total=%d',
				$counts['garbage'],
				$counts['orphan'],
				$counts['inactive'],
				$counts['retention'],
				$total
			) . "\n");
		}

		return $counts;
	}

	/**
	 * Drains every row matching $filter in batches bounded by $batchSize, until the filter is empty
	 * or the time budget runs out. Each batch is bounded by its ID snapshot, but the DELETE repeats
	 * the criterion filter on top of the ids: a row touched or protected between the snapshot and
	 * the delete no longer matches and survives. $deleted grows by the actually deleted row count
	 * after every batch, so the caller sees partial progress even if a later batch throws.
	 *
	 * $batchValidator re-checks the selected rows against state the row filter cannot see (the app
	 * registry) right before the DELETE and returns the line ids that are still safe to remove. Rows
	 * it rejects keep matching the select, so a batch reduced to zero ids ends the drain: the
	 * rejected rows are re-classified on the next daily run.
	 */
	private static function drain(array $filter, int $batchSize, callable $budgetExceeded, int &$deleted, ?callable $batchValidator = null): void
	{
		while (!$budgetExceeded())
		{
			$rows = ExternalLineTable::query()
				->setSelect(['ID', 'REST_APP_ID'])
				->setFilter($filter)
				->setLimit($batchSize)
				->fetchAll()
			;

			$ids = [];
			if ($batchValidator !== null)
			{
				$ids = $batchValidator($rows);
			}
			else
			{
				foreach ($rows as $row)
				{
					$ids[] = (int)$row['ID'];
				}
			}

			if (empty($ids))
			{
				break;
			}

			$deleteFilter = $filter;
			$deleteFilter['@ID'] = $ids;
			$deleted += ExternalLineTable::deleteBatch($deleteFilter);

			// Fewer rows than requested means this filter is exhausted.
			if (count($rows) < $batchSize)
			{
				break;
			}
		}
	}

	/**
	 * Drains a criterion whose filter is keyed by a list of rest app ids. The id list is walked in
	 * chunks (whereIn size cap); each chunk is drained in batches. Empty list means no query at all.
	 *
	 * @param int[] $appIds
	 * @param callable(int[]): array $filterFactory Builds the row filter for a chunk of app ids.
	 */
	private static function drainByAppIds(array $appIds, callable $filterFactory, int $batchSize, callable $budgetExceeded, int &$deleted, ?callable $batchValidator = null): void
	{
		foreach (array_chunk($appIds, self::CHUNK_SIZE) as $chunk)
		{
			if ($budgetExceeded())
			{
				break;
			}

			self::drain($filterFactory($chunk), $batchSize, $budgetExceeded, $deleted, $batchValidator);
		}
	}

	/**
	 * Batch validator for the inactive criterion: re-reads the app registry for the batch rows and
	 * keeps only lines whose owner app is still inactive. The registry snapshot taken at the start
	 * of the run can go stale within it - reinstalling an app flips ACTIVE back to 'Y' on the same
	 * row - and lines of a reactivated app must fall under the retention window, not this criterion.
	 * The re-check narrows the stale window from the whole run to the gap between this query and
	 * the batch DELETE.
	 *
	 * @param array<int, array<string, mixed>> $rows Selected rows with ID and REST_APP_ID.
	 * @return int[] Line ids still safe to delete.
	 */
	private static function keepLinesOfStillInactiveApps(array $rows): array
	{
		if (empty($rows))
		{
			return [];
		}

		$appIds = [];
		foreach ($rows as $row)
		{
			$appIds[(int)$row['REST_APP_ID']] = true;
		}

		$stillInactive = [];
		$cursor = AppTable::getList([
			'select' => ['ID'],
			'filter' => ['@ID' => array_keys($appIds), '=ACTIVE' => AppTable::INACTIVE],
		]);
		while ($app = $cursor->fetch())
		{
			$stillInactive[(int)$app['ID']] = true;
		}

		$ids = [];
		foreach ($rows as $row)
		{
			if (isset($stillInactive[(int)$row['REST_APP_ID']]))
			{
				$ids[] = (int)$row['ID'];
			}
		}

		return $ids;
	}

	/**
	 * Loads the rest app registry once and splits the app ids referenced by external lines into
	 * orphan (id absent from the registry or 0), inactive (ACTIVE = 'N') and active buckets.
	 *
	 * The DISTINCT scan filters only on REST_APP_ID IS NOT NULL (SIP lines never carry an app id),
	 * so it rides the leading prefix of the unique index instead of a full index scan.
	 *
	 * @return array{0: int[], 1: int[], 2: int[]} [orphanIds, inactiveIds, activeIds]
	 */
	private static function classifyLineApps(): array
	{
		$apps = [];
		$appCursor = AppTable::getList([
			'select' => ['ID', 'ACTIVE'],
		]);
		while ($app = $appCursor->fetch())
		{
			$apps[(int)$app['ID']] = (string)$app['ACTIVE'];
		}

		$lineAppIds = [];
		$rows = ExternalLineTable::query()
			->setSelect(['REST_APP_ID'])
			->whereNotNull('REST_APP_ID')
			->setGroup(['REST_APP_ID'])
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$lineAppIds[] = (int)$row['REST_APP_ID'];
		}

		$orphanIds = $inactiveIds = $activeIds = [];
		foreach ($lineAppIds as $appId)
		{
			if ($appId === 0 || !isset($apps[$appId]))
			{
				// Uninstalling an app flips ACTIVE/INSTALLED to 'N' but keeps the row, so a truly
				// missing id means the app record was hard-removed; id 0 is a broken reference.
				$orphanIds[] = $appId;
			}
			elseif ($apps[$appId] === AppTable::INACTIVE)
			{
				$inactiveIds[] = $appId;
			}
			else
			{
				$activeIds[] = $appId;
			}
		}

		return [$orphanIds, $inactiveIds, $activeIds];
	}

	// -------- criterion filters -----------------------------------------------------------------

	/**
	 * Garbage (c1): rest-app lines with no owner app at all, unused past the retention window.
	 */
	private static function garbageFilter(DateTime $expire): array
	{
		return self::withSettingsGuard([
			'=TYPE' => ExternalLineTable::TYPE_REST_APP,
			'=REST_APP_ID' => null,
			'<DATE_LAST_USED' => $expire,
		]);
	}

	/**
	 * Orphan (c2): lines of apps missing from the registry. No settings guard - the owner is gone,
	 * so there is nobody whose manual settings we could preserve. Age-guarded against fresh rows,
	 * and a recently used line survives too: a still-running transient call may reference it.
	 *
	 * @param int[] $appIds
	 */
	private static function orphanFilter(array $appIds, DateTime $minAge): array
	{
		return self::withRecentUseGuard([
			'=TYPE' => ExternalLineTable::TYPE_REST_APP,
			'@REST_APP_ID' => $appIds,
			[
				'LOGIC' => 'OR',
				['<DATE_CREATE' => $minAge],
				['=DATE_CREATE' => null],
			],
		], $minAge);
	}

	/**
	 * Inactive (c3): lines of uninstalled apps (ACTIVE = 'N'). Settings guard applies, the row must
	 * be old enough and not recently used (a still-running transient call may reference it); apps
	 * still installing (ACTIVE = 'Y', INSTALLED = 'N') are not in this set.
	 *
	 * @param int[] $appIds
	 */
	private static function inactiveFilter(array $appIds, DateTime $minAge): array
	{
		return self::withRecentUseGuard(self::withSettingsGuard([
			'=TYPE' => ExternalLineTable::TYPE_REST_APP,
			'@REST_APP_ID' => $appIds,
			[
				'LOGIC' => 'OR',
				['<DATE_CREATE' => $minAge],
				['=DATE_CREATE' => null],
			],
		]), $minAge);
	}

	/**
	 * Retention (c4): lines of active apps that have not been used within the retention window.
	 * DATE_LAST_USED < expire already implies the row is well past SAFE_MIN_AGE_DAYS.
	 *
	 * @param int[] $appIds
	 */
	private static function retentionFilter(array $appIds, DateTime $expire): array
	{
		return self::withSettingsGuard([
			'=TYPE' => ExternalLineTable::TYPE_REST_APP,
			'@REST_APP_ID' => $appIds,
			'<DATE_LAST_USED' => $expire,
		]);
	}

	/**
	 * Adds the recent-use guard: a line used after $minAge survives even when its owner app is gone
	 * or disabled, because an unfinished transient call may still reference the row by id.
	 */
	private static function withRecentUseGuard(array $filter, DateTime $minAge): array
	{
		$filter[] = [
			'LOGIC' => 'OR',
			['<DATE_LAST_USED' => $minAge],
			['=DATE_LAST_USED' => null],
		];

		return $filter;
	}

	/**
	 * Adds the shared settings guard: a line survives if it carries a name or has auto-create off,
	 * which marks it as manually configured. Only nameless, auto-create lines stay selectable.
	 */
	private static function withSettingsGuard(array $filter): array
	{
		$filter[] = [
			'LOGIC' => 'OR',
			['=NAME' => null],
			['=NAME' => ''],
		];
		$filter['=CRM_AUTO_CREATE'] = 'Y';

		return $filter;
	}

	private static function getLogger(): LoggerInterface
	{
		// Honour a logger configured in .settings.php; otherwise write the run summary to a module
		// log file. The summary is not gated behind a debug option.
		$logger = (new LoggerFactory(false))->createById(
			self::LOGGER_ID,
			isCheckEnabledFromRegistry: false,
			returnDefaultLoggerIfNotExists: false,
		);
		if ($logger !== null)
		{
			return $logger;
		}

		$path = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/modules/voximplant.log';

		return new FileLogger($path);
	}
}
