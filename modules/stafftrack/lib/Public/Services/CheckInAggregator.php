<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;
use Bitrix\StaffTrack\Internal\Model\CheckInByDayTable;

class CheckInAggregator
{
	private const DATE_FORMAT = 'Y-m-d';

	/**
	 * @param int $userId
	 * @param array $checkInRows
	 */
	public function aggregate(int $userId, array $checkInRows): void
	{
		$dateGroups = $this->groupByDate($checkInRows);

		foreach ($dateGroups as $group)
		{
			$this->saveRoute($userId, $group['checkIns'], $group['checkInIds']);
		}
	}

	private function groupByDate(array $rows): array
	{
		$groups = [];

		foreach ($rows as $row)
		{
			$dateKey = $row['DATE_CREATE']->format(self::DATE_FORMAT);

			$groups[$dateKey]['checkInIds'][] = (int)$row['ID'];

			$row['DATE_CREATE'] = $row['DATE_CREATE']->getTimestamp();
			$groups[$dateKey]['checkIns'][] = $row;
		}

		return $groups;
	}

	private function saveRoute(int $userId, array $checkIns, array $checkInIds): void
	{
		$timestamps = array_column($checkIns, 'DATE_CREATE');
		$dateStart = DateTime::createFromTimestamp(min($timestamps));
		$dateEnd = DateTime::createFromTimestamp(max($timestamps));

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$existingRoute = $this->findRouteByDate($userId, $dateStart);

			if ($existingRoute)
			{
				$success = $this->updateRoute($existingRoute, $dateStart, $dateEnd, $checkIns);
			}
			else
			{
				$success = $this->createRoute($userId, $dateStart, $dateEnd, $checkIns);
			}

			if ($success)
			{
				CheckInTable::deleteByIds($checkInIds);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
		}
	}

	private function findRouteByDate(int $userId, DateTime $date): array|false
	{
		$day = $date->format(self::DATE_FORMAT);
		$dayStart = DateTime::createFromTimestamp(strtotime($day . ' 00:00:00'));
		$dayEnd = DateTime::createFromTimestamp(strtotime($day . ' 23:59:59'));

		return CheckInByDayTable::query()
			->setSelect(['ID', 'GEO_DATA', 'CNT', 'DATE_CREATE', 'DATE_END'])
			->where('USER_ID', $userId)
			->where('DATE_CREATE', '>=', $dayStart)
			->where('DATE_CREATE', '<=', $dayEnd)
			->setLimit(1)
			->exec()
			->fetch();
	}

	private function updateRoute(array $existingRoute, DateTime $dateStart, DateTime $dateEnd, array $newCheckIns): bool
	{
		$existingCheckIns = json_decode($existingRoute['GEO_DATA'] ?? '[]', true) ?: [];

		$existingIds = array_flip(array_column($existingCheckIns, 'ID'));
		$uniqueNewCheckIns = array_filter(
			$newCheckIns,
			static fn(array $checkIn) => !isset($existingIds[$checkIn['ID']]),
		);

		if (empty($uniqueNewCheckIns))
		{
			return true;
		}

		$allCheckIns = array_merge($existingCheckIns, $uniqueNewCheckIns);
		usort($allCheckIns, static fn($a, $b) => $a['DATE_CREATE'] <=> $b['DATE_CREATE']);

		$existingStart = $existingRoute['DATE_CREATE'];
		$existingEnd = $existingRoute['DATE_END'];

		return CheckInByDayTable::update($existingRoute['ID'], [
			'DATE_CREATE' => ($dateStart->getTimestamp() < $existingStart->getTimestamp()) ? $dateStart : $existingStart,
			'DATE_END' => ($dateEnd->getTimestamp() > $existingEnd->getTimestamp()) ? $dateEnd : $existingEnd,
			'GEO_DATA' => json_encode($allCheckIns),
			'CNT' => count($allCheckIns),
		])->isSuccess();
	}

	private function createRoute(int $userId, DateTime $dateStart, DateTime $dateEnd, array $checkIns): bool
	{
		return CheckInByDayTable::add([
			'USER_ID' => $userId,
			'DATE_CREATE' => $dateStart,
			'DATE_END' => $dateEnd,
			'GEO_DATA' => json_encode($checkIns),
			'CNT' => count($checkIns),
		])->isSuccess();
	}
}
