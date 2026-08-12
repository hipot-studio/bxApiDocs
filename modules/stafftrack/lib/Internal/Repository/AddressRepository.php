<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Dictionary\AddressStatus;
use Bitrix\StaffTrack\Internal\Geo\GeohashKey;
use Bitrix\StaffTrack\Internal\Model\AddressTable;

class AddressRepository
{
	public static function findByGeohash(string $geohash): ?array
	{
		return AddressTable::getRow([
			'filter' => [
				'=GEOHASH_KEY' => GeohashKey::hash($geohash),
			],
		]);
	}

	public static function findResolvedByGeohash(string $geohash): ?array
	{
		return AddressTable::getRow([
			'filter' => [
				'=GEOHASH_KEY' => GeohashKey::hash($geohash),
				'=STATUS' => AddressStatus::RESOLVED->value,
			],
		]);
	}

	public static function createResolved(string $geohash, string $address): AddResult
	{
		return AddressTable::add([
			'GEOHASH_KEY' => GeohashKey::hash($geohash),
			'STATUS' => AddressStatus::RESOLVED->value,
			'ADDRESS' => $address,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function createFailed(string $geohash): AddResult
	{
		return AddressTable::add([
			'GEOHASH_KEY' => GeohashKey::hash($geohash),
			'STATUS' => AddressStatus::FAILED->value,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function markResolved(string $geohash, string $address): UpdateResult
	{
		return AddressTable::update(GeohashKey::hash($geohash), [
			'STATUS' => AddressStatus::RESOLVED->value,
			'ADDRESS' => $address,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function markFailed(string $geohash): UpdateResult
	{
		return AddressTable::update(GeohashKey::hash($geohash), [
			'STATUS' => AddressStatus::FAILED->value,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function touchLastUsed(string $geohash): void
	{
		AddressTable::update(GeohashKey::hash($geohash), [
			'LAST_USED' => new DateTime(),
		]);
	}

	public static function getStaleResolved(int $days, int $limit): array
	{
		$threshold = DateTime::createFromTimestamp(time() - $days * 86400);

		return AddressTable::getList([
			'select' => ['GEOHASH_KEY'],
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
			'select' => ['GEOHASH_KEY'],
			'filter' => [
				'STATUS' => AddressStatus::FAILED->value,
				'<DATE_RESOLVE' => $threshold,
			],
			'limit' => $limit,
		])->fetchAll();
	}

	public static function deleteByGeohashKeys(array $geohashKeys): void
	{
		if (!empty($geohashKeys))
		{
			AddressTable::deleteByFilter(['@GEOHASH_KEY' => $geohashKeys]);
		}
	}

	public static function findByKey(string $geohashKey): ?array
	{
		return AddressTable::getRow([
			'filter' => [
				'=GEOHASH_KEY' => $geohashKey,
			],
		]);
	}

	public static function createResolvedByKey(string $geohashKey, string $address): AddResult
	{
		return AddressTable::add([
			'GEOHASH_KEY' => $geohashKey,
			'STATUS' => AddressStatus::RESOLVED->value,
			'ADDRESS' => $address,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function createFailedByKey(string $geohashKey): AddResult
	{
		return AddressTable::add([
			'GEOHASH_KEY' => $geohashKey,
			'STATUS' => AddressStatus::FAILED->value,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function markResolvedByKey(string $geohashKey, string $address): UpdateResult
	{
		return AddressTable::update($geohashKey, [
			'STATUS' => AddressStatus::RESOLVED->value,
			'ADDRESS' => $address,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}

	public static function markFailedByKey(string $geohashKey): UpdateResult
	{
		return AddressTable::update($geohashKey, [
			'STATUS' => AddressStatus::FAILED->value,
			'DATE_RESOLVE' => new DateTime(),
		]);
	}
}
