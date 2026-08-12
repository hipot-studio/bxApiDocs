<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Geo\GeohashKey;
use Bitrix\StaffTrack\Internal\Model\AddressQueueTable;
use Bitrix\StaffTrack\Internal\Model\CheckInByDayTable;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;

class AddressQueueRepository
{
	private const ORPHAN_LOOKUP_DAYS = 30;

	public static function add(int $checkInId, string $geohash, int $userId, DateTime $dateCreate, int $viewerId = 0): bool
	{
		try
		{
			AddressQueueTable::add([
				'CHECK_IN_ID' => $checkInId,
				'GEOHASH_KEY' => GeohashKey::hash($geohash),
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

	/**
	 * @return array<int, array{GEOHASH_KEY: string, CHECK_IN_ID: int, LATITUDE: ?string, LONGITUDE: ?string}>
	 */
	public static function getUniqueKeysWithSampleCoords(int $limit): array
	{
		$rows = AddressQueueTable::getList([
			'select' => [
				'GEOHASH_KEY',
				'USER_ID',
				'MIN_CHECK_IN_ID',
			],
			'runtime' => [
				new ExpressionField('MIN_CHECK_IN_ID', 'MIN(%s)', ['CHECK_IN_ID']),
			],
			'group' => ['GEOHASH_KEY', 'USER_ID'],
			'limit' => $limit,
		])->fetchAll();

		if (empty($rows))
		{
			return [];
		}

		$coordsById = self::buildCoordsIndex($rows);

		$result = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['MIN_CHECK_IN_ID'];
			$coord = $coordsById[$id] ?? null;
			$result[] = [
				'GEOHASH_KEY' => (string)$row['GEOHASH_KEY'],
				'CHECK_IN_ID' => $id,
				'LATITUDE' => $coord['LATITUDE'] ?? null,
				'LONGITUDE' => $coord['LONGITUDE'] ?? null,
			];
		}

		return $result;
	}

	/**
	 * @param array<int, array{MIN_CHECK_IN_ID: int|string, USER_ID: int|string}> $queueRows
	 * @return array<int, array{LATITUDE: ?string, LONGITUDE: ?string}>  checkInId => coords
	 */
	private static function buildCoordsIndex(array $queueRows): array
	{
		$checkInIds = array_map(static fn(array $r) => (int)$r['MIN_CHECK_IN_ID'], $queueRows);

		$coordsRows = CheckInTable::getList([
			'select' => ['ID', 'LATITUDE', 'LONGITUDE'],
			'filter' => ['@ID' => $checkInIds],
		])->fetchAll();

		$coordsById = [];
		foreach ($coordsRows as $coord)
		{
			$coordsById[(int)$coord['ID']] = $coord;
		}

		$missingByUser = [];
		foreach ($queueRows as $row)
		{
			$id = (int)$row['MIN_CHECK_IN_ID'];
			if (!isset($coordsById[$id]))
			{
				$missingByUser[(int)$row['USER_ID']][] = $id;
			}
		}

		if (!empty($missingByUser))
		{
			$coordsById += self::resolveOrphansFromAggregates($missingByUser);
		}

		return $coordsById;
	}

	/**
	 * @param array<int, int[]> $missingByUser  userId => [checkInId, ...]
	 * @return array<int, array{LATITUDE: ?string, LONGITUDE: ?string}>
	 */
	private static function resolveOrphansFromAggregates(array $missingByUser): array
	{
		$resolved = [];
		$userIds = array_keys($missingByUser);
		$threshold = DateTime::createFromTimestamp(time() - self::ORPHAN_LOOKUP_DAYS * 86400);

		$aggregates = CheckInByDayTable::getList([
			'select' => ['USER_ID', 'GEO_DATA'],
			'filter' => [
				'@USER_ID' => $userIds,
				'>=DATE_CREATE' => $threshold,
			],
		])->fetchAll();

		foreach ($aggregates as $agg)
		{
			$userId = (int)$agg['USER_ID'];
			$needed = $missingByUser[$userId] ?? [];
			if (empty($needed))
			{
				continue;
			}

			$geo = json_decode((string)($agg['GEO_DATA'] ?? ''), true);
			if (!is_array($geo))
			{
				continue;
			}

			$neededFlip = array_flip($needed);
			foreach ($geo as $point)
			{
				$pid = (int)($point['ID'] ?? 0);
				if (!isset($neededFlip[$pid]))
				{
					continue;
				}

				$resolved[$pid] = [
					'LATITUDE' => $point['LATITUDE'] ?? null,
					'LONGITUDE' => $point['LONGITUDE'] ?? null,
				];
				unset($neededFlip[$pid]);
			}

			$missingByUser[$userId] = array_keys($neededFlip);
			if (empty($missingByUser[$userId]))
			{
				unset($missingByUser[$userId]);
			}
		}

		return $resolved;
	}

	public static function getByGeohashKey(string $geohashKey): array
	{
		return AddressQueueTable::getList([
			'filter' => [
				'=GEOHASH_KEY' => $geohashKey,
			],
		])->fetchAll();
	}

	public static function deleteByGeohashKey(string $geohashKey): void
	{
		AddressQueueTable::deleteByFilter(['=GEOHASH_KEY' => $geohashKey]);
	}
}
