<?php

namespace Bitrix\StaffTrack\Service\CheckIn;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Integration\Im\MessageService;
use Bitrix\StaffTrack\Internal\Repository\CheckInByDayRepository;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Services\MapDataCache;

class CheckInGeoCleanupService
{
	private const MODULE_ID = 'stafftrack';
	// Public because the day answer has to know it too: past that age the coordinates of the day are
	// gone, and what is left no longer measures the silence between points.
	public const RETENTION_MONTHS = 3;
	private const BATCH_SIZE = 500;
	// Budgeted by GEO_DATA weight, not by row count: a dense day weighs megabytes. The row cap bounds the
	// other extreme, where days of three points would stretch a batch into tens of thousands of rows.
	private const AGGREGATED_BATCH_BYTES = 8388608;
	private const AGGREGATED_BATCH_MAX_ROWS = 500;
	// Seed portion of the first weight query: LENGTH() over a LONGTEXT reads the value itself, so asking
	// for the whole row cap at once would read the window many times over to fill one batch.
	private const AGGREGATED_SIZE_SEED_ROWS = 8;
	private const SNAPSHOT_BATCH_SIZE = 200;
	private const SNAPSHOT_SUBDIR = 'stafftrack/static_map';
	private const WINDOW_OVERLAP_DAYS = 7;

	private const OPTION_CLEANED_UNTIL_RAW = 'clear_geo_cleanup_cleaned_until_raw';
	private const OPTION_CURSOR_RAW = 'clear_geo_cleanup_cursor_raw';
	private const OPTION_CLEANED_UNTIL_BY_DAY = 'clear_geo_cleanup_cleaned_until_by_day';
	private const OPTION_CURSOR_BY_DAY = 'clear_geo_cleanup_cursor_by_day';
	private const OPTION_CURSOR_SNAPSHOT = 'clear_geo_cleanup_cursor_snapshot';

	// Components of each cursor, in the order of the key its walk goes by. The keys of the raw and the
	// aggregated walks changed meaning, so they moved to option names of their own: the numbers left in
	// clear_geo_cleanup_offset_* are ids of another order and are no longer read.
	private const CURSOR_SIZE_RAW = 3;
	private const CURSOR_SIZE_BY_DAY = 2;
	private const CURSOR_SIZE_SNAPSHOT = 1;

	/** Cursors of the previous generation of the walks: read by nothing, so a reset takes them out. */
	private const RETIRED_OPTIONS = [
		'clear_geo_cleanup_offset_raw',
		'clear_geo_cleanup_offset_by_day',
	];

	private CheckInRepository $checkInRepository;
	private CheckInByDayRepository $checkInByDayRepository;
	private MessageService $messageService;

	private bool $mapDataDirty = false;

	public function __construct(
		CheckInRepository $checkInRepository,
		CheckInByDayRepository $checkInByDayRepository,
		MessageService $messageService,
	)
	{
		$this->checkInRepository = $checkInRepository;
		$this->checkInByDayRepository = $checkInByDayRepository;
		$this->messageService = $messageService;
	}

	/**
	 * @return void
	 */
	public function resetProgress(): void
	{
		$this->deleteOption(self::OPTION_CLEANED_UNTIL_RAW);
		$this->setCursor(self::OPTION_CURSOR_RAW, []);
		$this->deleteOption(self::OPTION_CLEANED_UNTIL_BY_DAY);
		$this->setCursor(self::OPTION_CURSOR_BY_DAY, []);
		$this->setCursor(self::OPTION_CURSOR_SNAPSHOT, []);

		foreach (self::RETIRED_OPTIONS as $option)
		{
			$this->deleteOption($option);
		}
	}

	/**
	 * @param int $timeLimitSeconds
	 * @return bool
	 */
	public function run(int $timeLimitSeconds): bool
	{
		$startTime = time();
		$threshold = $this->getThreshold();

		$hasMore = true;
		$hasMoreRaw = $this->clearRawCheckIns($threshold, $startTime, $timeLimitSeconds);

		if (!$this->isTimeExceeded($startTime, $timeLimitSeconds))
		{
			$hasMoreAggregated = $this->clearAggregated($threshold, $startTime, $timeLimitSeconds);

			if (!$this->isTimeExceeded($startTime, $timeLimitSeconds))
			{
				$hasMoreSnapshots = $this->clearSnapshots($threshold, $startTime, $timeLimitSeconds);
				$hasMore = $hasMoreRaw || $hasMoreAggregated || $hasMoreSnapshots;
			}
		}

		$this->flushMapDataCache();

		return $hasMore;
	}

	/**
	 * @return void
	 */
	private function flushMapDataCache(): void
	{
		if ($this->mapDataDirty)
		{
			MapDataCache::invalidateAll();
			$this->mapDataDirty = false;
		}
	}

	/**
	 * @param DateTime $threshold
	 * @param int $startTime
	 * @param int $timeLimitSeconds
	 * @return bool
	 */
	public function clearRawCheckIns(DateTime $threshold, int $startTime = 0, int $timeLimitSeconds = 0): bool
	{
		$cleanedUntil = $this->getCleanedUntil(self::OPTION_CLEANED_UNTIL_RAW, $threshold);
		$windowStart = $this->getWindowStart($cleanedUntil);
		$cursor = $this->getCursor(self::OPTION_CURSOR_RAW, self::CURSOR_SIZE_RAW);

		do
		{
			$rows = $this->checkInRepository->getStaleRawCheckIns($windowStart, $threshold, $cursor, self::BATCH_SIZE);
			if (empty($rows))
			{
				$this->finishWindow(self::OPTION_CLEANED_UNTIL_RAW, self::OPTION_CURSOR_RAW, $threshold);

				return false;
			}

			$messageIds = [];
			$dirtyIds = [];

			foreach ($rows as $row)
			{
				$hasGeo = (int)$row['HAS_GEO'] === 1;
				$rowMessages = $row['MESSAGE_IDS'];
				$hasMessages = is_array($rowMessages) && !empty($rowMessages);

				if (!$hasGeo && !$hasMessages)
				{
					continue;
				}

				$dirtyIds[] = (int)$row['ID'];
				$this->mapDataDirty = true;

				if ($hasMessages)
				{
					foreach ($rowMessages as $msgId)
					{
						$messageId = (int)$msgId;
						if ($messageId > 0)
						{
							$messageIds[] = $messageId;
						}
					}
				}
			}

			if (!empty($messageIds))
			{
				$this->messageService->deleteMessages($messageIds);
			}

			if (!empty($dirtyIds))
			{
				$this->checkInRepository->clearGeoFieldsByIds($dirtyIds);
			}

			// The batch comes back in the order of the key, so its last row is the high-water mark.
			$cursor = self::rawCursorOf($rows[array_key_last($rows)]);
			$this->setCursor(self::OPTION_CURSOR_RAW, $cursor);

			if (count($rows) < self::BATCH_SIZE)
			{
				$this->finishWindow(self::OPTION_CLEANED_UNTIL_RAW, self::OPTION_CURSOR_RAW, $threshold);

				return false;
			}
		}
		while (!$this->isTimeExceeded($startTime, $timeLimitSeconds));

		return true;
	}

	/**
	 * @param DateTime $threshold
	 * @param int $startTime
	 * @param int $timeLimitSeconds
	 * @return bool
	 */
	public function clearAggregated(DateTime $threshold, int $startTime = 0, int $timeLimitSeconds = 0): bool
	{
		$cleanedUntil = $this->getCleanedUntil(self::OPTION_CLEANED_UNTIL_BY_DAY, $threshold);
		$windowStart = $this->getWindowStart($cleanedUntil);
		$cursor = $this->getCursor(self::OPTION_CURSOR_BY_DAY, self::CURSOR_SIZE_BY_DAY);

		while (true)
		{
			// On entry into the iteration and not inside the batch alone: a batch whose rows were deleted
			// between the two queries gives the loop below no step at all, and an iteration that checks
			// the time nowhere would spin.
			if ($this->isTimeExceeded($startTime, $timeLimitSeconds))
			{
				return true;
			}

			$batch = $this->collectAggregatedBatch($windowStart, $threshold, $cursor);
			if (empty($batch['ids']))
			{
				$this->finishWindow(self::OPTION_CLEANED_UNTIL_BY_DAY, self::OPTION_CURSOR_BY_DAY, $threshold);

				return false;
			}

			$cursor = $batch['cursor'];

			foreach ($this->checkInByDayRepository->getRowsByIds($batch['ids']) as $row)
			{
				if ($this->processAggregatedRow($row))
				{
					$this->mapDataDirty = true;
				}

				// A heavy row costs tens of milliseconds, so the limit is checked per row. Rows come back
				// in the order of the walk, so the cursor may stand on this one - saved before returning,
				// or the next run would redo the batch from its beginning.
				if ($this->isTimeExceeded($startTime, $timeLimitSeconds))
				{
					$this->setCursor(self::OPTION_CURSOR_BY_DAY, self::aggregatedCursorOf($row));

					return true;
				}
			}

			$this->setCursor(self::OPTION_CURSOR_BY_DAY, $cursor);

			if ($batch['drained'])
			{
				$this->finishWindow(self::OPTION_CLEANED_UNTIL_BY_DAY, self::OPTION_CURSOR_BY_DAY, $threshold);

				return false;
			}
		}
	}

	/**
	 * Ids of the next batch, the cursor on its last row and whether the window is drained. Weights are asked
	 * for in portions rather than for the whole row cap at once, see AGGREGATED_SIZE_SEED_ROWS. Drained
	 * means a portion gave less than asked: a batch stopped by the byte budget or the row cap carries on.
	 *
	 * @param DateTime $windowStart
	 * @param DateTime $threshold
	 * @param int[] $cursor
	 * @return array{ids: int[], cursor: int[], drained: bool}
	 */
	private function collectAggregatedBatch(DateTime $windowStart, DateTime $threshold, array $cursor): array
	{
		$ids = [];
		$bytes = 0;

		while (count($ids) < self::AGGREGATED_BATCH_MAX_ROWS)
		{
			$limit = min(
				self::nextSizePortion($ids, $bytes),
				self::AGGREGATED_BATCH_MAX_ROWS - count($ids),
			);

			$sizes = $this->checkInByDayRepository->getStaleRowSizes($windowStart, $threshold, $cursor, $limit);
			if (empty($sizes))
			{
				return ['ids' => $ids, 'cursor' => $cursor, 'drained' => true];
			}

			foreach ($sizes as $size)
			{
				$rowBytes = (int)$size['GEO_LEN'];

				// The first row goes in whatever it weighs: a row heavier than the whole budget has to be
				// processed alone, and skipping it would leave it in the window for good.
				if (!empty($ids) && ($bytes + $rowBytes) > self::AGGREGATED_BATCH_BYTES)
				{
					return ['ids' => $ids, 'cursor' => $cursor, 'drained' => false];
				}

				$ids[] = (int)$size['ID'];
				$bytes += $rowBytes;
				$cursor = self::aggregatedCursorOf($size);
			}

			if (count($sizes) < $limit)
			{
				return ['ids' => $ids, 'cursor' => $cursor, 'drained' => true];
			}
		}

		return ['ids' => $ids, 'cursor' => $cursor, 'drained' => false];
	}

	/**
	 * Rows to ask the weights of next: what is left of the budget divided by the weight of a row of this
	 * window, plus one row so that the portion ending the batch is the one that overruns the budget. Before
	 * the first weight is known there is nothing to divide by and the seed is taken instead.
	 *
	 * @param int[] $ids rows already in the batch
	 * @param int $bytes their weight
	 * @return int
	 */
	private static function nextSizePortion(array $ids, int $bytes): int
	{
		if (empty($ids) || $bytes <= 0)
		{
			return self::AGGREGATED_SIZE_SEED_ROWS;
		}

		$averageRowBytes = (int)ceil($bytes / count($ids));
		$budgetLeft = max(0, self::AGGREGATED_BATCH_BYTES - $bytes);

		return (int)ceil($budgetLeft / $averageRowBytes) + 1;
	}

	/**
	 * The work {@see clearAggregated()} does over a row, over an explicit set of rows instead of a window.
	 * Touches no progress option, so the batch-cost probe of dev/public/geo-cleanup-probe - its only
	 * caller, there is no production one - cannot leave the agent believing a window is already done.
	 *
	 * @param int[] $ids
	 * @return int rows actually rewritten
	 */
	public function processRowsByIds(array $ids): int
	{
		$cleaned = 0;

		foreach ($this->checkInByDayRepository->getRowsByIds($ids) as $row)
		{
			if ($this->processAggregatedRow($row))
			{
				$this->mapDataDirty = true;
				$cleaned++;
			}
		}

		return $cleaned;
	}

	/**
	 * @param DateTime $threshold
	 * @param int $startTime
	 * @param int $timeLimitSeconds
	 * @return bool
	 */
	public function clearSnapshots(DateTime $threshold, int $startTime = 0, int $timeLimitSeconds = 0): bool
	{
		// b_file: TIMESTAMP_X and ID are monotonic (CFile stamps current time, files are not edited),
		// so a plain ID high-water-mark is sufficient. No daily reset, no terminal "done" marker:
		// newly aged files always have ID > cursor and are picked up on subsequent runs.
		$cursor = $this->getCursor(self::OPTION_CURSOR_SNAPSHOT, self::CURSOR_SIZE_SNAPSHOT)[0] ?? 0;

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$moduleId = $sqlHelper->forSql(self::MODULE_ID);
		$subDirPrefix = $sqlHelper->forSql(self::SNAPSHOT_SUBDIR);
		$thresholdSql = $sqlHelper->convertToDbDateTime($threshold);
		$batchSize = self::SNAPSHOT_BATCH_SIZE;

		do
		{
			$rows = $connection->query(
				"SELECT ID FROM b_file
				WHERE MODULE_ID = '{$moduleId}'
				AND SUBDIR LIKE '{$subDirPrefix}%'
				AND TIMESTAMP_X <= {$thresholdSql}
				AND ID > {$cursor}
				ORDER BY ID ASC
				LIMIT {$batchSize}",
			)->fetchAll();

			if (empty($rows))
			{
				return false;
			}

			$maxId = $cursor;
			foreach ($rows as $row)
			{
				$maxId = max($maxId, (int)$row['ID']);

				\CFile::Delete((int)$row['ID']);
				$this->mapDataDirty = true;
			}

			$cursor = $maxId;
			$this->setCursor(self::OPTION_CURSOR_SNAPSHOT, [$cursor]);

			if (count($rows) < self::SNAPSHOT_BATCH_SIZE)
			{
				return false;
			}
		}
		while (!$this->isTimeExceeded($startTime, $timeLimitSeconds));

		return true;
	}

	/**
	 * @param array $row
	 * @return bool
	 */
	private function processAggregatedRow(array $row): bool
	{
		$geoDataRaw = (string)($row['GEO_DATA'] ?? '');
		if ($geoDataRaw === '')
		{
			return false;
		}

		$points = json_decode($geoDataRaw, true);
		if (!is_array($points))
		{
			return false;
		}

		$messageIds = [];
		$hasGeoToClear = false;

		foreach ($points as $point)
		{
			if (
				($point['LATITUDE'] ?? null) !== null
				|| ($point['LONGITUDE'] ?? null) !== null
				|| ($point['ADDRESS'] ?? null) !== null
			)
			{
				$hasGeoToClear = true;
			}

			$pointMessageIds = $point['MESSAGE_IDS'] ?? [];
			if (is_array($pointMessageIds))
			{
				foreach ($pointMessageIds as $msgId)
				{
					$messageId = (int)$msgId;
					if ($messageId > 0)
					{
						$messageIds[] = $messageId;
						$hasGeoToClear = true;
					}
				}
			}
		}

		if (!$hasGeoToClear)
		{
			return false;
		}

		if (!empty($messageIds))
		{
			$this->messageService->deleteMessages($messageIds);
		}

		$cleanedPoints = [];
		foreach ($points as $point)
		{
			$point['LATITUDE'] = null;
			$point['LONGITUDE'] = null;
			$point['ADDRESS'] = null;
			$point['MESSAGE_IDS'] = [];

			$cleanedPoints[] = $point;
		}

		$this->checkInByDayRepository->updateGeoData(
			(int)$row['ID'],
			json_encode($cleanedPoints),
		);

		return true;
	}

	private function getThreshold(): DateTime
	{
		return (new DateTime())->add('-' . self::RETENTION_MONTHS . ' months');
	}

	/**
	 * @param string $key
	 * @param DateTime $threshold
	 * @return DateTime
	 */
	private function getCleanedUntil(string $key, DateTime $threshold): DateTime
	{
		$stored = Option::get(self::MODULE_ID, $key, '');
		if ($stored === '')
		{
			return clone $threshold;
		}

		return DateTime::createFromTimestamp((int)$stored);
	}

	/**
	 * @param DateTime $cleanedUntil
	 * @return DateTime
	 */
	private function getWindowStart(DateTime $cleanedUntil): DateTime
	{
		return (clone $cleanedUntil)->add('-' . self::WINDOW_OVERLAP_DAYS . ' days');
	}

	/**
	 * @param string $cleanedUntilKey
	 * @param string $cursorKey
	 * @param DateTime $threshold
	 * @return void
	 */
	private function finishWindow(string $cleanedUntilKey, string $cursorKey, DateTime $threshold): void
	{
		Option::set(self::MODULE_ID, $cleanedUntilKey, (string)$threshold->getTimestamp());
		$this->setCursor($cursorKey, []);
	}

	private function deleteOption(string $key): void
	{
		Option::delete(self::MODULE_ID, ['name' => $key]);
	}

	/**
	 * A call with no clock - $startTime left at its default - has no limit to exceed: the caller asked for
	 * the whole window rather than for a slice of a run. The agent always passes the moment its run started.
	 */
	private function isTimeExceeded(int $startTime, int $timeLimitSeconds): bool
	{
		if ($startTime <= 0)
		{
			return false;
		}

		return (time() - $startTime) >= $timeLimitSeconds;
	}

	/**
	 * Components of the stored cursor, in the order of the key, or an empty list to start at the beginning
	 * of the window. Anything that does not read as exactly $size numeric components gives an empty cursor
	 * rather than an error: the cleanup is idempotent, so rewalking a cleared stretch writes nothing.
	 *
	 * @param string $key
	 * @param int $size
	 * @return int[]
	 */
	private function getCursor(string $key, int $size): array
	{
		$stored = (string)Option::get(self::MODULE_ID, $key, '');
		$components = $stored === '' ? [] : explode(':', $stored);

		if (count($components) !== $size)
		{
			return [];
		}

		foreach ($components as $component)
		{
			// Not ctype_digit(): the extension is absent on portals and the call is fatal there. The /D
			// guards the trailing newline, the strict !== 1 the internal PCRE failure.
			if (preg_match('/^[0-9]+$/D', $component) !== 1)
			{
				return [];
			}
		}

		return array_map('intval', $components);
	}

	/**
	 * Timestamps go into the option as unix seconds rather than as a formatted date: they are never
	 * compared as strings, and parsing them back stays independent of the locale and the timezone of
	 * the process.
	 *
	 * @param string $key
	 * @param int[] $components
	 * @return void
	 */
	private function setCursor(string $key, array $components): void
	{
		Option::set(self::MODULE_ID, $key, implode(':', array_map('intval', $components)));
	}

	/**
	 * @param array $row
	 * @return int[] (DATE_CREATE, USER_ID, ID) of the row - the key the raw walk goes by
	 */
	private static function rawCursorOf(array $row): array
	{
		$dateCreate = $row['DATE_CREATE'] ?? null;

		return [
			$dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : 0,
			(int)$row['USER_ID'],
			(int)$row['ID'],
		];
	}

	/**
	 * @param array $row
	 * @return int[] (DATE_END, ID) of the row - the key the aggregated walk goes by
	 */
	private static function aggregatedCursorOf(array $row): array
	{
		$dateEnd = $row['DATE_END'] ?? null;

		return [
			$dateEnd instanceof DateTime ? $dateEnd->getTimestamp() : 0,
			(int)$row['ID'],
		];
	}
}
