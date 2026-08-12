<?php

namespace Bitrix\StaffTrack\Infrastructure\Agent\CheckIn;

use Bitrix\Main\Application;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Repository\AddressQueueRepository;
use Bitrix\StaffTrack\Internal\Repository\AddressRepository;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Provider\GeoProvider;
use Bitrix\StaffTrack\Public\Services\AddressCache;
use Bitrix\StaffTrack\Public\Services\MapDataCache;

class AddressResolveAgent
{
	public const QUEUE_LOCK_NAME = 'stafftrack_address_resolve';

	private const BATCH_SIZE = 50;
	private const CONTINUATION_DELAY = 5;

	public static function run(): string
	{
		self::processAndContinueIfNeeded();

		return '';
	}

	public static function runContinuation(): string
	{
		$hasMore = self::processAndContinueIfNeeded();

		if ($hasMore)
		{
			return 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressResolveAgent::runContinuation();';
		}

		return '';
	}

	/**
	 * Caller MUST hold the QUEUE_LOCK_NAME lock.
	 */
	public static function runOnceLocked(): bool
	{
		try
		{
			if (self::processBatch())
			{
				self::scheduleContinuation();
			}

			return true;
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('AddressResolveAgent::runOnceLocked error: ' . $e->getMessage());

			return false;
		}
	}

	private static function processAndContinueIfNeeded(): bool
	{
		$connection = Application::getConnection();
		if (!$connection->lock(self::QUEUE_LOCK_NAME, 0))
		{
			return false;
		}

		$hasMore = false;

		try
		{
			$hasMore = self::processBatch();

			if ($hasMore)
			{
				self::scheduleContinuation();
			}
		}
		catch (\Throwable $e)
		{
			AddMessage2Log('AddressResolveAgent error: ' . $e->getMessage());
		}
		finally
		{
			$connection->unlock(self::QUEUE_LOCK_NAME);
		}

		return $hasMore;
	}

	private static function processBatch(): bool
	{
		$rows = AddressQueueRepository::getUniqueKeysWithSampleCoords(self::BATCH_SIZE);
		if (empty($rows))
		{
			return false;
		}

		$repository = new CheckInRepository();

		foreach ($rows as $row)
		{
			$key = (string)$row['GEOHASH_KEY'];
			$lat = $row['LATITUDE'];
			$lon = $row['LONGITUDE'];

			try
			{
				if ($lat === null || $lon === null)
				{
					AddMessage2Log('AddressResolveAgent: missing coords for key [' . $key . ']');
					AddressQueueRepository::deleteByGeohashKey($key);
					continue;
				}

				$address = self::resolveByCoords($key, (float)$lat, (float)$lon);

				if ($address !== null)
				{
					$userCheckIns = self::applyAddressFromQueue($repository, $key, $address);
					self::sendPushToUsers($userCheckIns);
				}
				else
				{
					AddressQueueRepository::deleteByGeohashKey($key);
				}
			}
			catch (\Throwable $e)
			{
				AddMessage2Log('AddressResolveAgent resolve error [' . $key . ']: ' . $e->getMessage());
				AddressQueueRepository::deleteByGeohashKey($key);
			}
		}

		return count($rows) >= self::BATCH_SIZE;
	}

	private static function resolveByCoords(string $key, float $lat, float $lon): ?string
	{
		$existing = AddressRepository::findByKey($key);
		if ($existing !== null && ($existing['ADDRESS'] ?? '') !== '')
		{
			return $existing['ADDRESS'];
		}

		$geoProvider = new GeoProvider($lat, $lon);
		$result = $geoProvider->generateAddress();

		$address = '';
		if ($result->isSuccess())
		{
			$address = $result->getData()['address'] ?? '';
		}

		if ($address === '')
		{
			$existing !== null
				? AddressRepository::markFailedByKey($key)
				: AddressRepository::createFailedByKey($key);

			return null;
		}

		$saveResult = $existing !== null
			? AddressRepository::markResolvedByKey($key, $address)
			: AddressRepository::createResolvedByKey($key, $address);

		if (!$saveResult->isSuccess())
		{
			return null;
		}

		AddressCache::setByKey($key, $address);

		return $address;
	}

	/**
	 * @return array<int, array<int, string>> recipientId => [checkinId => address]
	 */
	private static function applyAddressFromQueue(CheckInRepository $repository, string $key, string $address): array
	{
		$queueItems = AddressQueueRepository::getByGeohashKey($key);
		if (empty($queueItems))
		{
			return [];
		}

		$recipientCheckIns = [];
		$checkInIds = [];
		$authorIds = [];
		foreach ($queueItems as $item)
		{
			$checkInId = (int)$item['CHECK_IN_ID'];
			$userId = (int)$item['USER_ID'];
			$viewerId = (int)($item['VIEWER_ID'] ?? 0);

			$checkInIds[$checkInId] = true;
			$authorIds[$userId] = true;

			$recipientId = ($viewerId > 0) ? $viewerId : $userId;
			$recipientCheckIns[$recipientId][$checkInId] = $address;
		}

		$repository->updateAddressByIds(array_keys($checkInIds), $address);

		AddressQueueRepository::deleteByGeohashKey($key);

		MapDataCache::invalidateForEmployees(array_keys($authorIds));

		return $recipientCheckIns;
	}

	/**
	 * @param array<int, array<int, string>> $resolvedByUser recipientId => [checkinId => address]
	 */
	private static function sendPushToUsers(array $resolvedByUser): void
	{
		foreach ($resolvedByUser as $userId => $checkIns)
		{
			Pull\PushSender::send(
				(int)$userId,
				Pull\PushCommand::CHECK_IN_ADDRESS_RESOLVED,
				$checkIns,
			);
		}
	}

	private static function scheduleContinuation(): void
	{
		$agentName = 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressResolveAgent::runContinuation();';

		\CAgent::RemoveAgent($agentName, 'stafftrack');
		\CAgent::AddAgent(
			$agentName,
			'stafftrack',
			'N',
			self::CONTINUATION_DELAY,
			'',
			'Y',
			ConvertTimeStamp(time() + self::CONTINUATION_DELAY, 'FULL'),
		);
	}
}
