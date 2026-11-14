<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

use Bitrix\StaffTrack\Public\Services\DailyStatsReader;

/**
 * Check-ins of the team on one date, counted off the daily statistics rather than off the points of the map:
 * an employee without a single coordinate keeps his count. The statistics are read by the build, once for the
 * facts and for the points of the map alike, and handed over.
 */
class CheckInDayFactsProvider
{
	/**
	 * @param int[] $userIds
	 * @param array<int, array>|null $statsByUser userId => statistics row of the date, as
	 *     {@see DailyStatsReader::read()} or, on a date still open to new points,
	 *     {@see DailyStatsReader::readWithoutBackFill()} answers; null when they could not be read
	 * @return array<int, array{trusted: bool, total: int}> a value per employee asked about, zeroes included
	 */
	public function getFactsOfStats(array $userIds, ?array $statsByUser): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($statsByUser === null)
		{
			return array_fill_keys($userIds, ['trusted' => false, 'total' => 0]);
		}

		$facts = [];
		foreach ($userIds as $userId)
		{
			$facts[$userId] = [
				'trusted' => true,
				'total' => self::totalOf($statsByUser[$userId] ?? null),
			];
		}

		return $facts;
	}

	/**
	 * An open stay counts as the check-in it will become: the map already shows the employee inside it.
	 */
	private static function totalOf(?array $statsRow): int
	{
		if ($statsRow === null)
		{
			return 0;
		}

		return (int)$statsRow['CNT'] + (int)$statsRow['HAS_OPEN_ENTER'];
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private static function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
