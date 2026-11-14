<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider\Presence;

use Bitrix\Main\Type\Date;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;

/**
 * The zone the check-ins of a team on one date are stamped with: the offset from the server zone every point is
 * written with (USER_TIMEZONE), the one the panel of the route prints that day by.
 *
 * It is read off the last point the daily statistics of the date name, so a team costs one short query and never
 * a day of points. A check-in writes the statistics of its date itself; the rows a date closed to new points
 * lacks are written lazily, by the reading the facts of the same date are taken off, which the list does first.
 */
class CheckInDayZoneReader
{
	private const DATE_FORMAT = 'Y-m-d';

	private CheckInRepository $repository;

	public function __construct(?CheckInRepository $repository = null)
	{
		$this->repository = $repository ?? new CheckInRepository();
	}

	/**
	 * @param int[] $userIds
	 * @param string $localDate 'Y-m-d', named as the facts of the date are
	 * @return array<int, int> userId => offset from the server zone in seconds, only for the employees whose
	 *     point of the date is still in the table and carries a stamp
	 */
	public function read(array $userIds, string $localDate): array
	{
		$userIds = array_values(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		));
		if ($userIds === [])
		{
			return [];
		}

		$rows = $this->repository->getLastCheckInZoneStampsByUsers(
			$userIds,
			new Date($localDate, self::DATE_FORMAT),
		);

		$stamps = [];
		foreach ($rows as $row)
		{
			$stamps[(int)$row['USER_ID']] = (int)$row['USER_TIMEZONE'];
		}

		return $stamps;
	}
}
