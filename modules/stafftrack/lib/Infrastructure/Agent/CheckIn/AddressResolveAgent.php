<?php

namespace Bitrix\StaffTrack\Infrastructure\Agent\CheckIn;

use Bitrix\StaffTrack\Internal\Geo\Geohash;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Repository\AddressQueueRepository;
use Bitrix\StaffTrack\Internal\Repository\AddressRepository;
use Bitrix\StaffTrack\Internal\Repository\CheckInRepository;
use Bitrix\StaffTrack\Public\Provider\GeoProvider;
use Bitrix\StaffTrack\Public\Services\AddressCache;
use Bitrix\StaffTrack\Public\Services\MapDataCache;

class AddressResolveAgent
{
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

	private static function processAndContinueIfNeeded(): bool
	{
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

		return $hasMore;
	}

	private static function processBatch(): bool
	{
		$geohashes = AddressQueueRepository::getUniqueGeohashes(self::BATCH_SIZE);
		if (empty($geohashes))
		{
			return false;
		}

		$repository = new CheckInRepository();

		foreach ($geohashes as $geohash)
		{
			try
			{
				$address = self::resolveGeohash($geohash);

				if ($address !== null)
				{
					$userCheckIns = self::applyAddressFromQueue($repository, $geohash, $address);
					self::sendPushToUsers($userCheckIns);
				}
				else
				{
					AddressQueueRepository::deleteByGeohash($geohash);
				}
			}
			catch (\Throwable $e)
			{
				AddMessage2Log('AddressResolveAgent resolve error [' . $geohash . ']: ' . $e->getMessage());
				AddressQueueRepository::deleteByGeohash($geohash);
			}
		}

		return count($geohashes) >= self::BATCH_SIZE;
	}

	private static function resolveGeohash(string $geohash): ?string
	{
		$existing = AddressRepository::findByGeohash($geohash);
		if ($existing !== null && ($existing['ADDRESS'] ?? '') !== '')
		{
			return $existing['ADDRESS'];
		}

		$decoded = Geohash::decode($geohash);
		if ($decoded['latErr'] >= 90.0 || $decoded['lonErr'] >= 180.0)
		{
			return null;
		}

		$geoProvider = new GeoProvider($decoded['lat'], $decoded['lon']);
		$result = $geoProvider->generateAddress();

		$address =  '';
		if ($result->isSuccess())
		{
			$address = $result->getData()['address'] ?? '';
		}

		if ($address === '')
		{
			$existing !== null
				? AddressRepository::markFailed($geohash)
				: AddressRepository::createFailed($geohash);

			return null;
		}

		$saveResult = $existing !== null
			? AddressRepository::markResolved($geohash, $address)
			: AddressRepository::createResolved($geohash, $address);

		if (!$saveResult->isSuccess())
		{
			return null;
		}

		AddressCache::set($geohash, $address);

		return $address;
	}

	/**
	 * @return array<int, array<int, string>> recipientId => [checkinId => address]
	 */
	private static function applyAddressFromQueue(CheckInRepository $repository, string $geohash, string $address): array
	{
		$queueItems = AddressQueueRepository::getByGeohash($geohash);
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

		AddressQueueRepository::deleteByGeohash($geohash);

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
