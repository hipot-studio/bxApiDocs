<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Provider\CheckInNormalizer;

/**
 * The point every employee has on the map of a date: the check-in his statistics of the date name last, read
 * off its own row or, once the day is folded, off the folded row of the day. A check-in without coordinates
 * is no point.
 *
 * The map draws these points and the presence list counts them, so the two cannot disagree about a date.
 */
class LatestCheckInReader
{
	private CheckInRepository $repository;
	private DailyStatsReader $statsReader;

	public function __construct(?CheckInRepository $repository = null, ?DailyStatsReader $statsReader = null)
	{
		$this->repository = $repository ?? new CheckInRepository();
		$this->statsReader = $statsReader ?? new DailyStatsReader($this->repository);
	}

	/**
	 * @param int[] $userIds
	 * @param string $localDate 'Y-m-d'; a check-in belongs to it by the offset of its own employee
	 * @return array<int, array{stats: array, checkIn: array}> userId => the statistics row of the date and the
	 *     check-in it names last, for the employees with a point only, in the order the statistics come in
	 */
	public function read(array $userIds, string $localDate): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if ($userIds === [])
		{
			return [];
		}

		return $this->readOfStats($userIds, $this->statsReader->read($userIds, $localDate), $localDate);
	}

	/**
	 * The same points off statistics of the date read already, for a caller that needs them for more than the
	 * points and reads them once.
	 *
	 * @param int[] $userIds
	 * @param array<int, array> $statsByUser userId => statistics row of the date, as {@see DailyStatsReader::read()}
	 *     or, on a date still open to new points, {@see DailyStatsReader::readWithoutBackFill()} answers; a row of
	 *     anybody not asked about is left out
	 * @param string $localDate 'Y-m-d' the statistics are of
	 * @return array<int, array{stats: array, checkIn: array}> as {@see read()} answers
	 */
	public function readOfStats(array $userIds, array $statsByUser, string $localDate): array
	{
		$statsByUser = array_intersect_key($statsByUser, array_flip(array_map('intval', $userIds)));
		if ($statsByUser === [])
		{
			return [];
		}

		$checkInsById = $this->resolveLastCheckIns(self::ownersOfLastCheckIns($statsByUser), $localDate);

		$points = [];
		foreach ($statsByUser as $statsRow)
		{
			$checkIn = $checkInsById[(int)$statsRow['LAST_CHECK_IN_ID']] ?? null;
			if ($checkIn === null || !CheckInNormalizer::hasGeo($checkIn))
			{
				continue;
			}

			$points[(int)$statsRow['USER_ID']] = ['stats' => $statsRow, 'checkIn' => $checkIn];
		}

		return $points;
	}

	/**
	 * @param array<int, array> $statsByUser
	 * @return array<int, int> id of the last check-in of the date => the employee whose statistics name it
	 */
	private static function ownersOfLastCheckIns(array $statsByUser): array
	{
		$owners = [];
		foreach ($statsByUser as $statsRow)
		{
			$id = (int)$statsRow['LAST_CHECK_IN_ID'];
			if ($id > 0)
			{
				$owners[$id] = (int)$statsRow['USER_ID'];
			}
		}

		return $owners;
	}

	/**
	 * @param array<int, int> $ownerByCheckInId
	 * @return array<int, array>
	 */
	private function resolveLastCheckIns(array $ownerByCheckInId, string $localDate): array
	{
		if ($ownerByCheckInId === [])
		{
			return [];
		}

		$checkInsById = array_column($this->repository->getCheckInsByIds(array_keys($ownerByCheckInId)), null, 'ID');

		$unresolved = array_diff_key($ownerByCheckInId, $checkInsById);
		if ($unresolved === [])
		{
			return $checkInsById;
		}

		foreach ($this->findFoldedCheckIns($unresolved, $localDate) as $id => $point)
		{
			$checkInsById[$id] = $point;
		}

		return $checkInsById;
	}

	/**
	 * The fold keeps the ids of the rows it deletes, so a check-in missing from its table is looked for among
	 * the points of the folded days around the date - of its own employee alone, whose statistics name it.
	 * The owners are read {@see DailyStatsReader::USERS_PER_READING} at a time, and of a decoded day only the
	 * points looked for are kept. The last check-in of a date comes at its end, so the days are decoded from the
	 * latest one back, and a reading stops once it has found all it looks for.
	 *
	 * @param array<int, int> $ownerByCheckInId the check-ins looked for => their employees
	 * @return array<int, array>
	 */
	private function findFoldedCheckIns(array $ownerByCheckInId, string $localDate): array
	{
		[$dateStart, $dateEnd] = DailyStatsReader::wideDayBoundaries($localDate);

		$idsByOwner = [];
		foreach ($ownerByCheckInId as $id => $ownerId)
		{
			$idsByOwner[$ownerId][] = $id;
		}

		$found = [];
		foreach (array_chunk($idsByOwner, DailyStatsReader::USERS_PER_READING, true) as $idsOfReading)
		{
			$lookedFor = array_fill_keys(array_merge(...array_values($idsOfReading)), true);
			$points = $this->repository->iterateAllUsersAggregatedPointsByPeriod(
				array_keys($idsOfReading),
				$dateStart,
				$dateEnd,
				latestDayFirst: true,
			);
			foreach ($points as $point)
			{
				$id = (int)($point['ID'] ?? 0);
				if (!isset($lookedFor[$id]))
				{
					continue;
				}

				$found[$id] = $point;
				unset($lookedFor[$id]);
				if ($lookedFor === [])
				{
					break;
				}
			}
		}

		return $found;
	}
}
