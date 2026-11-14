<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Internal\Service\CheckIn\EmptyBackFillMemory;

/**
 * The daily check-in statistics of a team on one local date.
 *
 * The write of a check-in writes the row of its date in the same transaction, so a reading may take the rows as
 * written ({@see readWithoutBackFill()}). The map reads through {@see read()} - read, write the rows missing for
 * the check-ins of the window, read again - and so does the presence list for a date closed to new points, so the
 * two cannot disagree about the same employee there. Coordinates are not looked at.
 *
 * On a date closed to new points the backfill is paid once for an employee without a row, whatever team he is read
 * in: whoever it found nothing for will never have a check-in of the date, and that is remembered of him
 * ({@see EmptyBackFillMemory}).
 */
class DailyStatsReader
{
	/**
	 * Employees whose check-ins of the date one reading holds, as
	 * {@see \Bitrix\StaffTrack\Internal\Service\CheckIn\CheckInVisitCounter} reads a team: a folded day is decoded
	 * whole, so the memory of a reading follows this number rather than the team.
	 */
	public const USERS_PER_READING = 25;

	private const DATE_FORMAT = 'Y-m-d';
	private const DAY_END_OFFSET = 86399;
	private const MAX_TIMEZONE_OFFSET = 50400;

	private CheckInRepository $repository;

	public function __construct(?CheckInRepository $repository = null)
	{
		$this->repository = $repository ?? new CheckInRepository();
	}

	/**
	 * @param int[] $userIds
	 * @param string $localDate 'Y-m-d'; a row belongs to it by the offset of its own employee
	 * @return array<int, array> userId => statistics row of that date
	 */
	public function read(array $userIds, string $localDate): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$date = new Date($localDate, self::DATE_FORMAT);
		$statsRows = $this->repository->getDailyStatsByUsers($userIds, $date);

		$usersWithoutStats = array_values(array_diff(
			$userIds,
			array_map(static fn (array $row): int => (int)$row['USER_ID'], $statsRows),
		));

		// Whoever did not check in on the date never gets a row, so most readings find somebody without one;
		// the statistics are read again only when a row was really written for somebody.
		if ($usersWithoutStats !== [] && $this->backFillMissingStatsOnce($usersWithoutStats, $localDate, $date))
		{
			$statsRows = $this->repository->getDailyStatsByUsers($userIds, $date);
		}

		return self::byUser($statsRows);
	}

	/**
	 * The rows of the date as they are written, none written here: on a date still open to new points every
	 * check-in has come with its row, so a missing row means no check-in and costs no reading of the window.
	 *
	 * @param int[] $userIds
	 * @param string $localDate 'Y-m-d'; a row belongs to it by the offset of its own employee
	 * @return array<int, array> userId => statistics row of that date
	 */
	public function readWithoutBackFill(array $userIds, string $localDate): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		return self::byUser($this->repository->getDailyStatsByUsers($userIds, new Date($localDate, self::DATE_FORMAT)));
	}

	/**
	 * The window holding the date in every zone; the rows are cut by the offset of each employee afterwards.
	 *
	 * @param string $localDate 'Y-m-d'
	 * @return array{0: DateTime, 1: DateTime}
	 */
	public static function wideDayBoundaries(string $localDate): array
	{
		$midnightUtc = (int)strtotime($localDate . ' 00:00:00 UTC');
		$endOfDayUtc = $midnightUtc + self::DAY_END_OFFSET;

		return [
			DateTime::createFromTimestamp($midnightUtc - self::MAX_TIMEZONE_OFFSET),
			DateTime::createFromTimestamp($endOfDayUtc + self::MAX_TIMEZONE_OFFSET),
		];
	}

	/**
	 * @param array $checkIn row carrying DATE_CREATE and USER_TIMEZONE
	 * @param string $localDate 'Y-m-d'
	 */
	public static function isCheckInOnLocalDate(array $checkIn, string $localDate): bool
	{
		$timestamp = self::timestampOf($checkIn['DATE_CREATE'] ?? null);
		if ($timestamp === null)
		{
			return false;
		}

		return date(self::DATE_FORMAT, $timestamp + (int)($checkIn['USER_TIMEZONE'] ?? 0)) === $localDate;
	}

	/**
	 * The backfill, skipped on a date closed to new points for whoever it has once found nothing for there, in
	 * whatever team: the rest are scanned, and whoever it finds nothing for now is remembered beside them. Every point
	 * such a date still gets - the LEAVE admitted past the window among them - writes the row of its date, and an
	 * employee with a row is not looked up, so nobody remembered ever has to be forgotten.
	 *
	 * @param int[] $userIds
	 * @return bool whether anybody had check-ins of the date to write a row of
	 */
	private function backFillMissingStatsOnce(array $userIds, string $localDate, Date $date): bool
	{
		if (!PointAcceptanceService::isDateClosedToNewPoints($localDate))
		{
			return $this->backFillMissingStats($userIds, $localDate, $date) !== [];
		}

		$memory = new EmptyBackFillMemory();
		$unscannedUserIds = array_values(array_diff($userIds, $memory->recall($localDate, $userIds)));
		if ($unscannedUserIds === [])
		{
			return false;
		}

		$backFilledUserIds = $this->backFillMissingStats($unscannedUserIds, $localDate, $date);
		$memory->remember($localDate, array_values(array_diff($unscannedUserIds, $backFilledUserIds)));

		return $backFilledUserIds !== [];
	}

	/**
	 * Writes the statistics of the employees who have check-ins of the date but no row yet, from the points
	 * in the order the counter of a day is built in. The employees are read USERS_PER_READING at a time.
	 *
	 * @param int[] $userIds
	 * @return int[] whoever had check-ins of the date to write a row of
	 */
	private function backFillMissingStats(array $userIds, string $localDate, Date $date): array
	{
		$statsUpdater = null;
		$backFilledUserIds = [];

		foreach (array_chunk($userIds, self::USERS_PER_READING) as $readingUserIds)
		{
			$checkInsByUser = $this->readCheckInsOfTheDate($readingUserIds, $localDate);

			foreach ($readingUserIds as $userId)
			{
				$checkIns = $checkInsByUser[$userId] ?? [];
				if ($checkIns === [])
				{
					continue;
				}

				usort($checkIns, static function (array $a, array $b): int
				{
					return self::localMomentOf($a) <=> self::localMomentOf($b)
						?: (int)($a['ID'] ?? 0) <=> (int)($b['ID'] ?? 0)
					;
				});

				$statsUpdater ??= new DailyStatsUpdater();
				$statsUpdater->backFillForUser($userId, $checkIns, $date);
				$backFilledUserIds[] = $userId;
			}
		}

		return $backFilledUserIds;
	}

	/**
	 * The check-ins of the date of one reading, the raw rows first and the folded points after them. The rows of
	 * the window are read whole only for the employees their moments place on the date - the others have
	 * nothing encrypted read at all - and of a folded day only the points of the date are kept.
	 *
	 * @param int[] $userIds
	 * @return array<int, array[]> userId => his check-ins of the date
	 */
	private function readCheckInsOfTheDate(array $userIds, string $localDate): array
	{
		[$dateStart, $dateEnd] = self::wideDayBoundaries($localDate);

		$ownersOfTheDate = [];
		foreach ($this->repository->iterateAllUsersCheckInMomentsByPeriod($userIds, $dateStart, $dateEnd) as $moment)
		{
			if (self::isCheckInOnLocalDate($moment, $localDate))
			{
				$ownersOfTheDate[(int)$moment['USER_ID']] = true;
			}
		}

		$checkInsByUser = [];
		if ($ownersOfTheDate !== [])
		{
			$rawRows = $this->repository->getAllUsersCheckInByPeriod(array_keys($ownersOfTheDate), $dateStart, $dateEnd);
			foreach ($rawRows as $row)
			{
				if (self::isCheckInOnLocalDate($row, $localDate))
				{
					$checkInsByUser[(int)$row['USER_ID']][] = $row;
				}
			}
		}

		foreach ($this->repository->iterateAllUsersAggregatedPointsByPeriod($userIds, $dateStart, $dateEnd) as $point)
		{
			if (self::isCheckInOnLocalDate($point, $localDate))
			{
				$checkInsByUser[(int)$point['USER_ID']][] = $point;
			}
		}

		return $checkInsByUser;
	}

	/**
	 * @param array[] $statsRows
	 * @return array<int, array> userId => statistics row
	 */
	private static function byUser(array $statsRows): array
	{
		$statsByUser = [];
		foreach ($statsRows as $row)
		{
			$statsByUser[(int)$row['USER_ID']] = $row;
		}

		return $statsByUser;
	}

	private static function localMomentOf(array $checkIn): int
	{
		return (int)self::timestampOf($checkIn['DATE_CREATE'] ?? null) + (int)($checkIn['USER_TIMEZONE'] ?? 0);
	}

	private static function timestampOf(mixed $dateCreate): ?int
	{
		if ($dateCreate instanceof DateTime)
		{
			return $dateCreate->getTimestamp();
		}

		return is_int($dateCreate) ? $dateCreate : null;
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
