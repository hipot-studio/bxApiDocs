<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Dictionary\AddressStatus;
use Bitrix\StaffTrack\Internal\Model\AddressTable;

class AddressRepository
{
	public static function findByGeohash(string $geohash): ?array
	{
		return AddressTable::getRow([
			'filter' => [
				'=GEOHASH' => $geohash,
			],
		]);
	}

	public static function findResolvedByGeohash(string $geohash): ?array
	{
		return AddressTable::getRow([
			'filter' => [
				'=GEOHASH' => $geohash,
				'=STATUS' => AddressStatus::RESOLVED->value,
			],
		]);
	}

	public static function createResolved(string $geohash, string $address): AddResult
	{
		return AddressTable::add([
			'GEOHASH' => $geohash,
			'STATUS' => AddressStatus::RESOLVED->value,
			'ADDRESS' => $address,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function createFailed(string $geohash): AddResult
	{
		return AddressTable::add([
			'GEOHASH' => $geohash,
			'STATUS' => AddressStatus::FAILED->value,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function markResolved(string $geohash, string $address): UpdateResult
	{
		return AddressTable::update($geohash, [
			'STATUS' => AddressStatus::RESOLVED->value,
			'ADDRESS' => $address,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function markFailed(string $geohash): UpdateResult
	{
		return AddressTable::update($geohash, [
			'STATUS' => AddressStatus::FAILED->value,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function touchLastUsed(string $geohash): void
	{
		AddressTable::update($geohash, [
			'LAST_USED' => new DateTime(),
		]);
	}

	public static function getStaleResolved(int $days, int $limit): array
	{
		$threshold = DateTime::createFromTimestamp(time() - $days * 86400);

		return AddressTable::getList([
			'select' => ['GEOHASH'],
			'filter' => [
				'<LAST_USED' => $threshold,
			],
			'limit' => $limit,
		])->fetchAll();
	}

	public static function getStaleFailed(int $days, int $limit): array
	{
		$threshold = DateTime::createFromTimestamp(time() - $days * 86400);

		return AddressTable::getList([
			'select' => ['GEOHASH'],
			'filter' => [
				'STATUS' => AddressStatus::FAILED->value,
				'<DATE_RESOLVE' => $threshold,
			],
			'limit' => $limit,
		])->fetchAll();
	}

	public static function deleteByGeohashes(array $geohashes): void
	{
		if (!empty($geohashes))
		{
			AddressTable::deleteByFilter(['@GEOHASH' => $geohashes]);
		}
	}
}
