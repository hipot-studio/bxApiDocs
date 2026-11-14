<?php

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Internal\Repository\DailyStatsManualCountRepository;

class ManualCountFiller
{
	// The start of the 2026 annual summary period; check-ins exist only since April 2026, so earlier days hold no rows.
	public const BACKFILL_FROM = '2026-01-01';
	public const BACKFILL_ROWS_PER_STEP = 20;

	private CheckInRepository $checkInRepository;
	private DailyStatsManualCountRepository $statsRepository;

	public function __construct(
		?CheckInRepository $checkInRepository = null,
		?DailyStatsManualCountRepository $statsRepository = null,
	)
	{
		$this->checkInRepository = $checkInRepository ?? new CheckInRepository();
		$this->statsRepository = $statsRepository ?? new DailyStatsManualCountRepository();
	}

	public function backfill(int $afterId, Date $closedUntil, int $limit = self::BACKFILL_ROWS_PER_STEP): ?int
	{
		$from = new Date(self::BACKFILL_FROM, 'Y-m-d');
		$rows = $this->statsRepository->getUncountedRows($from, $closedUntil, $afterId, $limit);

		if ($rows === [])
		{
			return $this->statsRepository->hasUncounted($from) ? 0 : null;
		}

		$this->fill($rows);

		return (int)end($rows)['ID'];
	}

	public function countDay(int $userId, Date $localDate): int
	{
		return $this->countUsersOnDate([$userId], $localDate)[$userId] ?? 0;
	}

	/**
	 * @param array<int, array{ID: int, USER_ID: int, LOCAL_DATE: Date}> $rows
	 */
	public function fill(array $rows): int
	{
		$rowsByDate = [];
		foreach ($rows as $row)
		{
			$rowsByDate[(string)$row['LOCAL_DATE']->format('Y-m-d')][] = $row;
		}

		foreach ($rowsByDate as $dateRows)
		{
			$userIds = array_map(static fn (array $row): int => (int)$row['USER_ID'], $dateRows);
			$counts = $this->countUsersOnDate($userIds, $dateRows[0]['LOCAL_DATE']);

			foreach ($dateRows as $row)
			{
				$this->statsRepository->fillManualCount((int)$row['ID'], $counts[(int)$row['USER_ID']] ?? 0);
			}
		}

		return count($rows);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	private function countUsersOnDate(array $userIds, Date $localDate): array
	{
		[$dateStart, $dateEnd] = CheckInDayRowsReader::getWideDayBoundaries($localDate);
		$localDateString = (string)$localDate->format('Y-m-d');
		$userIds = array_values(array_unique($userIds));

		$countedIds = [];
		$rawCheckIns = $this->checkInRepository->iterateAllUsersManualCheckInMomentsByPeriod(
			$userIds,
			$dateStart,
			$dateEnd,
		);
		foreach ($rawCheckIns as $checkIn)
		{
			if (self::isOnLocalDate($checkIn, $localDateString))
			{
				$countedIds[(int)$checkIn['USER_ID']][(int)$checkIn['ID']] = true;
			}
		}

		$foldedPoints = $this->checkInRepository->iterateAllUsersAggregatedManualPointsByPeriod(
			$userIds,
			$dateStart,
			$dateEnd,
		);
		foreach ($foldedPoints as $point)
		{
			if (self::isOnLocalDate($point, $localDateString))
			{
				$countedIds[(int)($point['USER_ID'] ?? 0)][(int)($point['ID'] ?? 0)] = true;
			}
		}

		return array_map('count', $countedIds);
	}

	private static function isOnLocalDate(array $row, string $localDateString): bool
	{
		$dateCreate = $row['DATE_CREATE'] ?? null;
		$timestamp = $dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : (int)$dateCreate;

		return date('Y-m-d', $timestamp + (int)($row['USER_TIMEZONE'] ?? 0)) === $localDateString;
	}
}
