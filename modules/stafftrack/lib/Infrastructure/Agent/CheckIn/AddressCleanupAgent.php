<?php

namespace Bitrix\StaffTrack\Infrastructure\Agent\CheckIn;

use Bitrix\StaffTrack\Internal\Repository\AddressRepository;

class AddressCleanupAgent
{
	private const STALE_RESOLVED_DAYS = 90;
	private const STALE_FAILED_DAYS = 7;
	private const BATCH_SIZE = 500;
	private const CONTINUATION_DELAY = 60;

	public static function run(): string
	{
		self::processCleanup();

		return 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressCleanupAgent::run();';
	}

	public static function runContinuation(): string
	{
		$hasMore = self::processCleanup();

		if ($hasMore)
		{
			return 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressCleanupAgent::runContinuation();';
		}

		return '';
	}

	private static function processCleanup(): bool
	{
		$hasMore = false;

		try
		{
			$deletedResolved = self::deleteStaleResolved();
			$deletedFailed = self::deleteStaleFailed();

			$hasMore = ($deletedResolved >= self::BATCH_SIZE) || ($deletedFailed >= self::BATCH_SIZE);

			if ($hasMore)
			{
				self::scheduleContinuation();
			}
		}
		catch (\Throwable $e)
		{

		}

		return $hasMore;
	}

	private static function deleteStaleResolved(): int
	{
		$rows = AddressRepository::getStaleResolved(self::STALE_RESOLVED_DAYS, self::BATCH_SIZE);

		$geohashes = array_column($rows, 'GEOHASH');
		if (empty($geohashes))
		{
			return 0;
		}

		AddressRepository::deleteByGeohashes($geohashes);

		return count($geohashes);
	}

	private static function deleteStaleFailed(): int
	{
		$rows = AddressRepository::getStaleFailed(self::STALE_FAILED_DAYS, self::BATCH_SIZE);

		$geohashes = array_column($rows, 'GEOHASH');
		if (empty($geohashes))
		{
			return 0;
		}

		AddressRepository::deleteByGeohashes($geohashes);

		return count($geohashes);
	}

	private static function scheduleContinuation(): void
	{
		$agentName = 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressCleanupAgent::runContinuation();';

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
