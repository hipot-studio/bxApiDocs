<?php

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Services\CheckInMarkerAggregator;

/**
 * The rows an employee left inside a window: the raw points and the folded ones of the same span, raised
 * together and put in one order. Every reading of a route starts here, so the timeline of the day and the
 * report of the evening are told one and the same day - what they build on top of it is where they differ.
 */
class CheckInDayRowsReader
{
	private const DESCRIPTION_ENTER = 'ENTER';
	private const DESCRIPTION_LEAVE = 'LEAVE';
	private const DAY_LENGTH_SEC = 86399;
	private const MAX_TIMEZONE_OFFSET = 50400;

	private CheckInRepository $repository;
	private CheckInMarkerAggregator $aggregator;

	public function __construct(?CheckInRepository $repository = null, ?CheckInMarkerAggregator $aggregator = null)
	{
		$this->repository = $repository ?? new CheckInRepository();
		$this->aggregator = $aggregator ?? new CheckInMarkerAggregator();
	}

	/**
	 * The window as a reading of markers needs it: the points and nothing they carry for a screen.
	 *
	 * @return array<int, array>
	 */
	public function readWindow(int $userId, DateTime $from, DateTime $to): array
	{
		return $this->collect(
			$this->repository->getUserRouteByPeriod($userId, $from, $to),
			$this->repository->getAggregatedPointsByPeriod($userId, $from, $to),
		);
	}

	/**
	 * The same window with the attachments and the messages of its points, for a reading that resolves them.
	 *
	 * @return array<int, array>
	 */
	public function readWindowWithAttachments(int $userId, DateTime $from, DateTime $to): array
	{
		return $this->collect(
			$this->repository->getUserCheckInByPeriod($userId, $from, $to),
			$this->repository->getAggregatedPointsByPeriod($userId, $from, $to),
		);
	}

	/**
	 * Only what the employee marked himself, raw and folded alike - for a reading that counts his own
	 * check-ins rather than the route they lie on.
	 *
	 * @return array<int, array>
	 */
	public function readManualWindow(int $userId, DateTime $from, DateTime $to): array
	{
		return $this->collect(
			$this->repository->getUserManualCheckInByPeriod($userId, $from, $to),
			$this->repository->getUserAggregatedManualPointsByPeriod($userId, $from, $to),
		);
	}

	/**
	 * The rows the markers of a local day are built from, for one employee or for a whole team, read in
	 * two steps: the marker events, then the technical points of the spans the merge has to look at.
	 * Which spans those are is answered by {@see CheckInMarkerAggregator::collectMergeGapIntervals()},
	 * so no threshold of the merge lives here.
	 *
	 * The track between the spans never leaves the database: it is what makes this reading cheap enough
	 * for a list of employees, while giving the aggregator the same markers the whole day would.
	 *
	 * @param int[] $userIds
	 * @return array<int, array<int, array>> employee id => rows of his day, ordered by time
	 */
	public function readMarkerRowsByUsers(array $userIds, Date $localDate): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		if (empty($userIds))
		{
			return [];
		}

		[$dateStart, $dateEnd] = self::getWideDayBoundaries($localDate);
		$localDateString = (string)$localDate->format('Y-m-d');

		// The aggregated part of a day is one row of encrypted JSON, so it is read and decoded once:
		// both selections run over what it leaves in memory.
		$archivedMarkers = [];
		$archivedTechnical = [];
		foreach ($this->repository->getAllUsersAggregatedPointsByPeriod($userIds, $dateStart, $dateEnd) as $point)
		{
			if (!self::isCheckInOnLocalDate($point, $localDateString))
			{
				continue;
			}

			if (self::isMarkerRow($point))
			{
				$archivedMarkers[] = $point;
			}
			else
			{
				$archivedTechnical[] = $point;
			}
		}

		$rowsByUser = array_fill_keys($userIds, []);
		$wanted = array_flip($userIds);
		$markerRows = array_merge(
			self::filterByLocalDate(
				$this->repository->getAllUsersMarkerCheckInByPeriod($userIds, $dateStart, $dateEnd),
				$localDateString,
			),
			$archivedMarkers,
		);
		foreach ($markerRows as $row)
		{
			$rowUserId = (int)($row['USER_ID'] ?? 0);
			if (isset($wanted[$rowUserId]))
			{
				$rowsByUser[$rowUserId][] = $row;
			}
		}

		$intervalsByUser = [];
		foreach ($rowsByUser as $rowUserId => $rows)
		{
			$intervals = $this->aggregator->collectMergeGapIntervals(AggregatorEventMapper::fromRows($rows));
			if (!empty($intervals))
			{
				$intervalsByUser[$rowUserId] = $intervals;
			}
		}

		if (!empty($intervalsByUser))
		{
			$technicalRows = array_merge(
				self::filterByLocalDate(
					$this->repository->getAllUsersTechnicalCheckInsInIntervals($intervalsByUser),
					$localDateString,
				),
				self::pointsInsideIntervals($archivedTechnical, $intervalsByUser),
			);
			foreach ($technicalRows as $row)
			{
				$rowUserId = (int)($row['USER_ID'] ?? 0);
				if (isset($intervalsByUser[$rowUserId]))
				{
					$rowsByUser[$rowUserId][] = $row;
				}
			}
		}

		foreach ($rowsByUser as &$rows)
		{
			$rows = self::orderedByTime($rows);
		}
		unset($rows);

		return $rowsByUser;
	}

	/**
	 * The window a local day can fall anywhere inside: it is read wide enough to hold the day of any
	 * offset, and cut down to the day itself afterwards.
	 *
	 * @return array{0: DateTime, 1: DateTime}
	 */
	public static function getWideDayBoundaries(Date $localDate): array
	{
		$midnightUtc = (int)strtotime($localDate->format('Y-m-d') . ' 00:00:00 UTC');

		return [
			DateTime::createFromTimestamp($midnightUtc - self::MAX_TIMEZONE_OFFSET),
			DateTime::createFromTimestamp($midnightUtc + self::DAY_LENGTH_SEC + self::MAX_TIMEZONE_OFFSET),
		];
	}

	/**
	 * The rule the marker step selects rows by in SQL, applied to the points of an aggregated day: they
	 * arrive decoded and are split in memory instead.
	 */
	private static function isMarkerRow(array $row): bool
	{
		return (int)($row['ENTITY_TYPE'] ?? 0) === CheckInType::MANUAL->value
			|| in_array((string)($row['DESCRIPTION'] ?? ''), [self::DESCRIPTION_ENTER, self::DESCRIPTION_LEAVE], true);
	}

	/**
	 * @param array<int, array> $rows
	 * @return array<int, array>
	 */
	private static function filterByLocalDate(array $rows, string $localDateString): array
	{
		return array_values(array_filter(
			$rows,
			static fn (array $row): bool => self::isCheckInOnLocalDate($row, $localDateString),
		));
	}

	private static function isCheckInOnLocalDate(array $row, string $localDateString): bool
	{
		$timestamp = self::timestampOf($row);
		$userTimezone = (int)($row['USER_TIMEZONE'] ?? 0);

		return date('Y-m-d', $timestamp + $userTimezone) === $localDateString;
	}

	/**
	 * @param array<int, array> $rows
	 * @param array<int, array<int, array{0: int, 1: int}>> $intervalsByUser
	 * @return array<int, array>
	 */
	private static function pointsInsideIntervals(array $rows, array $intervalsByUser): array
	{
		$inside = [];
		foreach ($rows as $row)
		{
			$timestamp = self::timestampOf($row);
			foreach ($intervalsByUser[(int)($row['USER_ID'] ?? 0)] ?? [] as [$low, $high])
			{
				if ($timestamp > $low && $timestamp < $high)
				{
					$inside[] = $row;
					break;
				}
			}
		}

		return $inside;
	}

	/**
	 * @param array<int, array> $rows
	 * @return array<int, array>
	 */
	private static function orderedByTime(array $rows): array
	{
		usort($rows, static function (array $a, array $b): int
		{
			return self::timestampOf($a) <=> self::timestampOf($b)
				?: (int)($a['ID'] ?? 0) <=> (int)($b['ID'] ?? 0);
		});

		return $rows;
	}

	/**
	 * The offset a row carries is the one the employee kept at the moment he left the point, so it answers
	 * before his profile does. A window can carry none: the stamp is nullable, and a day folded by an older
	 * fold kept nothing to read. Then the profile answers - the hours of the server are those of whoever
	 * runs the reading, and they are the day of nobody.
	 *
	 * @param array<int, array> $rows
	 */
	public static function resolveTimezoneOffset(array $rows, int $userId): int
	{
		foreach ($rows as $row)
		{
			if (isset($row['USER_TIMEZONE']))
			{
				return (int)$row['USER_TIMEZONE'];
			}
		}

		return (int)\CTimeZone::GetOffset($userId, true);
	}

	/**
	 * @param iterable $rawRows rows of the window as they are stored, objects of a collection or arrays
	 * @param array<int, array> $aggregatedPoints points of the same window raised out of the folded days
	 * @return array<int, array>
	 */
	private function collect(iterable $rawRows, array $aggregatedPoints): array
	{
		$rows = [];
		foreach ($rawRows as $row)
		{
			$rows[] = is_array($row) ? $row : $row->collectValues();
		}

		foreach ($aggregatedPoints as $point)
		{
			$rows[] = $point;
		}

		return self::orderedByTime($rows);
	}

	private static function timestampOf(array $row): int
	{
		$dateCreate = $row['DATE_CREATE'] ?? null;

		return $dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : (int)$dateCreate;
	}
}
