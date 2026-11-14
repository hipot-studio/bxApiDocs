<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Metrics;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Model\SyncLogTable;
use Bitrix\Disk\Internal\Repository\Sync\SyncMetricRepository;
use Bitrix\Disk\Internal\Repository\Sync\SyncSessionRepository;
use Bitrix\Disk\Internal\Service\Sync\Journal\CompletenessStepper;
use Bitrix\Disk\Internal\Service\Sync\Journal\SafeWindow;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Closure;

/**
 * The state of the contour no single call sees: how far the journal trails its writers and its
 * retention, how far the reconciliation trails the portal, how old the live cursors are - written as
 * gauges of the current hour - and the tombstones of the journal, folded into counters that outlive it.
 *
 * The fold walks the journal by identifier from its own mark up to the watermark of the safe window, so
 * a record still on its way in is never counted and a record already counted is never met again. A tick
 * reads no more than a range of identifiers FOLD_RANGE_FACTOR portions wide, from the first record past
 * the mark, so a sparse journal costs a tick no more than its range. The mark moves after the counters
 * are written: a run that stopped between the two counts again at most the portion, or the tombstones of
 * the range if fewer, rather than losing them.
 */
final class MetricsSnapshot
{
	public const OPTION_FOLD_ID = 'disk_sync_metrics_fold_id';

	public const DEFAULT_FOLD_PORTION = 10000;
	/** How many portions wide the range of identifiers a tick reads is. */
	public const FOLD_RANGE_FACTOR = 10;

	public const NAME_GAUGE = 'gauge';
	public const NAME_TOMBSTONE = 'tombstone';

	public const GAUGE_PUBLISH_LAG = 'journal.publish_lag_seconds';
	public const GAUGE_CLEANUP_LAG = 'journal.cleanup_lag_seconds';
	public const GAUGE_RECONCILIATION_LAG = 'journal.reconciliation_lag_seconds';
	public const GAUGE_LIVE_CURSORS = 'cursor.live';
	public const GAUGE_CURSOR_AGE_MEDIAN = 'cursor.age_median_seconds';
	public const GAUGE_CURSOR_AGE_P95 = 'cursor.age_p95_seconds';
	public const GAUGE_CURSOR_AGE_MAX = 'cursor.age_max_seconds';

	/** Two nodes folding one range would count it twice. */
	private const FOLD_LOCK_NAME = 'disk_sync_metrics_fold';
	private const FOLD_LOCK_TIMEOUT_SECONDS = 0;

	private const CURSOR_PAGE = 1000;

	private const MIN_PORTION = 1;

	private const HOUR_FORMAT = 'Y-m-d H:00:00';
	private const DATE_FORMAT = 'Y-m-d H:i:s';

	private SyncMetricRepository $metrics;
	private SyncSessionRepository $sessions;
	private CompletenessStepper $stepper;
	private SyncLimits $limits;
	private Closure $watermark;
	private Closure $now;
	private Closure $readFoldMark;
	private Closure $writeFoldMark;

	/**
	 * @param (callable(): int)|null $watermark the greatest identifier of the journal a reader may take in
	 * @param (callable(): int)|null $now the clock the ages are measured against, in unix time
	 * @param (callable(): int)|null $readFoldMark the last identifier the fold has counted up to
	 * @param (callable(int): void)|null $writeFoldMark keeps the identifier the fold has counted up to
	 */
	public function __construct(
		?SyncMetricRepository $metrics = null,
		?SyncSessionRepository $sessions = null,
		?CompletenessStepper $stepper = null,
		?SyncLimits $limits = null,
		?callable $watermark = null,
		?callable $now = null,
		?callable $readFoldMark = null,
		?callable $writeFoldMark = null,
	)
	{
		$this->limits = $limits ?? new SyncLimits();
		$this->metrics = $metrics ?? new SyncMetricRepository();
		$this->sessions = $sessions ?? new SyncSessionRepository();
		$this->now = $now === null ? static fn (): int => time() : Closure::fromCallable($now);
		$this->stepper = $stepper ?? new CompletenessStepper(limits: $this->limits, now: $this->now);

		$limits = $this->limits;
		$this->watermark = $watermark === null
			? static fn (): int => (new SafeWindow($limits))->maxPublishedId()
			: Closure::fromCallable($watermark);

		$this->readFoldMark = $readFoldMark === null
			? static fn (): int => (int)Option::get(Driver::INTERNAL_MODULE_ID, self::OPTION_FOLD_ID, '0')
			: Closure::fromCallable($readFoldMark);
		$this->writeFoldMark = $writeFoldMark === null
			? static function (int $id): void {
				Option::set(Driver::INTERNAL_MODULE_ID, self::OPTION_FOLD_ID, (string)$id);
			}
			: Closure::fromCallable($writeFoldMark);
	}

	/**
	 * @param int|null $portion how many tombstones one call folds at most
	 * @return array{
	 *     publishLagSeconds: int,
	 *     cleanupLagSeconds: int,
	 *     reconciliationLagSeconds: int,
	 *     liveCursors: int,
	 *     cursorAgeMedian: int,
	 *     cursorAgeP95: int,
	 *     cursorAgeMax: int,
	 *     foldedTombstones: int
	 * }
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function take(?int $portion = null): array
	{
		$now = ($this->now)();
		$watermark = ($this->watermark)();

		$folded = $this->fold($watermark, max(self::MIN_PORTION, $portion ?? self::DEFAULT_FOLD_PORTION));
		$cursors = $this->cursorAges($now);

		$result = [
			'publishLagSeconds' => $this->publishLag($watermark, $now),
			'cleanupLagSeconds' => $this->cleanupLag($now),
			'reconciliationLagSeconds' => $this->stepper->currentLagSeconds(),
			'liveCursors' => count($cursors),
			'cursorAgeMedian' => self::percentile($cursors, 50),
			'cursorAgeP95' => self::percentile($cursors, 95),
			'cursorAgeMax' => $cursors === [] ? 0 : max($cursors),
			'foldedTombstones' => $folded,
		];

		$hour = $this->metrics->hourOf();
		$gauges = [
			self::GAUGE_PUBLISH_LAG => $result['publishLagSeconds'],
			self::GAUGE_CLEANUP_LAG => $result['cleanupLagSeconds'],
			self::GAUGE_RECONCILIATION_LAG => $result['reconciliationLagSeconds'],
			self::GAUGE_LIVE_CURSORS => $result['liveCursors'],
			self::GAUGE_CURSOR_AGE_MEDIAN => $result['cursorAgeMedian'],
			self::GAUGE_CURSOR_AGE_P95 => $result['cursorAgeP95'],
			self::GAUGE_CURSOR_AGE_MAX => $result['cursorAgeMax'],
		];
		foreach ($gauges as $label => $value)
		{
			$this->metrics->setGauge($label, $value, $hour);
		}

		return $result;
	}

	/**
	 * The age of the oldest record a reader cannot take in yet: the first one above the watermark.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function publishLag(int $watermark, int $now): int
	{
		$row = SyncLogTable::query()
			->setSelect(['CREATED_AT'])
			->where('ID', '>', $watermark)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		if (!is_array($row) || !$row['CREATED_AT'] instanceof DateTime)
		{
			return 0;
		}

		return max(0, $now - $row['CREATED_AT']->getTimestamp());
	}

	/**
	 * How far the oldest record stands past the retention.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function cleanupLag(int $now): int
	{
		$row = SyncLogTable::query()
			->registerRuntimeField(
				new ExpressionField('OLDEST_AT', 'MIN(%s)', 'CREATED_AT', ['data_type' => 'datetime']),
			)
			->setSelect(['OLDEST_AT'])
			->fetch()
		;

		if (!is_array($row) || !$row['OLDEST_AT'] instanceof DateTime)
		{
			return 0;
		}

		return max(0, $now - $row['OLDEST_AT']->getTimestamp() - $this->limits->getJournalRetentionSeconds());
	}

	/**
	 * @return int[] the ages of the live cursors, ascending
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function cursorAges(int $now): array
	{
		$since = DateTime::createFromTimestamp($now - $this->limits->getJournalRetentionSeconds());
		$ages = [];
		$afterId = 0;

		do
		{
			$page = $this->sessions->findLiveReadAges($since, $afterId, self::CURSOR_PAGE);
			foreach ($page as $row)
			{
				$ages[] = $row['AGE'];
				$afterId = $row['ID'];
			}
		}
		while (count($page) === self::CURSOR_PAGE);

		sort($ages);

		return $ages;
	}

	/**
	 * One portion of tombstones between the mark and the watermark, counted by reason into the hour of the
	 * record and into the running total. The tick reads no more than FOLD_RANGE_FACTOR portions of
	 * identifiers from the first record past the mark; a stop between the counters and the mark counts
	 * again at most min(portion, tombstones of the range).
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	private function fold(int $watermark, int $portion): int
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::FOLD_LOCK_NAME, self::FOLD_LOCK_TIMEOUT_SECONDS))
		{
			return 0;
		}

		try
		{
			$mark = ($this->readFoldMark)();
			if ($watermark <= $mark)
			{
				return 0;
			}

			// The first record past the mark: a mark far under the journal costs no empty ticks.
			$first = SyncLogTable::query()
				->setSelect(['ID'])
				->where('ID', '>', $mark)
				->where('ID', '<=', $watermark)
				->setOrder(['ID' => 'ASC'])
				->setLimit(1)
				->fetch()
			;
			if (!is_array($first))
			{
				($this->writeFoldMark)($watermark);

				return 0;
			}

			$low = (int)$first['ID'];
			$high = min($low + self::FOLD_RANGE_FACTOR * $portion - 1, $watermark);

			$rows = SyncLogTable::query()
				->setSelect(['ID', 'TOMBSTONE_REASON', 'CREATED_AT'])
				->where('ID', '>=', $low)
				->where('ID', '<=', $high)
				->whereNotNull('TOMBSTONE_REASON')
				->setOrder(['ID' => 'ASC'])
				->setLimit($portion)
				->fetchAll()
			;

			$hourly = [];
			$total = [];
			foreach ($rows as $row)
			{
				$reason = (string)$row['TOMBSTONE_REASON'];
				$hour = $row['CREATED_AT']->format(self::HOUR_FORMAT);
				$hourly[$reason][$hour] = ($hourly[$reason][$hour] ?? 0) + 1;
				$total[$reason] = ($total[$reason] ?? 0) + 1;
			}

			foreach ($hourly as $reason => $hours)
			{
				foreach ($hours as $hour => $count)
				{
					$this->countTombstones((string)$reason, new DateTime($hour, self::DATE_FORMAT), $count);
				}
			}
			foreach ($total as $reason => $count)
			{
				$this->countTombstones((string)$reason, $this->metrics->totalWindow(), $count);
			}

			// A short portion means the range is walked to its end, holes included.
			$newMark = count($rows) < $portion ? $high : (int)$rows[array_key_last($rows)]['ID'];
			($this->writeFoldMark)($newMark);

			return count($rows);
		}
		finally
		{
			$connection->unlock(self::FOLD_LOCK_NAME);
		}
	}

	/**
	 * @throws SqlQueryException
	 */
	private function countTombstones(string $reason, DateTime $window, int $count): void
	{
		$this->metrics->increment(self::NAME_TOMBSTONE, $reason, '', '', 0, $window, $count);
	}

	/**
	 * Nearest-rank percentile of an ascending list.
	 *
	 * @param int[] $sorted
	 */
	private static function percentile(array $sorted, int $percent): int
	{
		if ($sorted === [])
		{
			return 0;
		}

		$rank = (int)ceil($percent / 100 * count($sorted));

		return $sorted[max(1, $rank) - 1];
	}
}
