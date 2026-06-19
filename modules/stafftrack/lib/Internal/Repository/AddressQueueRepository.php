<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\StaffTrack\Internal\Model\AddressQueueTable;
use \Bitrix\Main\Type\DateTime;
class AddressQueueRepository
{
	public static function add(int $checkInId, string $geohash, int $userId, DateTime $dateCreate, int $viewerId = 0): bool
	{
		try
		{
			AddressQueueTable::add([
				'CHECK_IN_ID' => $checkInId,
				'GEOHASH' => $geohash,
				'USER_ID' => $userId,
				'DATE_CREATE' => $dateCreate,
				'VIEWER_ID' => $viewerId,
			]);

			return true;
		}
		catch (\Exception $exception)
		{
			return false;
		}
	}

	public static function getUniqueGeohashes(int $limit): array
	{
		$rows = AddressQueueTable::getList([
			'select' => ['GEOHASH'],
			'group' => ['GEOHASH'],
			'limit' => $limit,
		])->fetchAll();

		return array_column($rows, 'GEOHASH');
	}

	public static function getByGeohash(string $geohash): array
	{
		return AddressQueueTable::getList([
			'filter' => [
				'=GEOHASH' => $geohash,
			],
		])->fetchAll();
	}

	public static function deleteByGeohash(string $geohash): void
	{
		AddressQueueTable::deleteByFilter(['=GEOHASH' => $geohash]);
	}
}
