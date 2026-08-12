<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Application;
use Bitrix\StaffTrack\Infrastructure\Agent\CheckIn\AddressResolveAgent;
use Bitrix\StaffTrack\Internal\Geo\Geohash;
use Bitrix\StaffTrack\Internal\Repository\AddressQueueRepository;

class AddressResolverService
{
	private const FALLBACK_AGENT_DELAY = 30;

	public function enqueue(array $checkIns, int $viewerId = 0): void
	{
		try
		{
			$this->processEnqueue($checkIns, $viewerId);
		}
		catch (\Throwable $exception)
		{

		}
	}

	private function processEnqueue(array $checkIns, int $viewerId = 0): void
	{
		$added = false;

		foreach ($checkIns as $checkIn)
		{
			$latitude = (float)($checkIn['latitude'] ?? 0);
			$longitude = (float)($checkIn['longitude'] ?? 0);

			if (!empty($checkIn['address']) || ($latitude == 0 && $longitude == 0))
			{
				continue;
			}

			$checkInId = (int)($checkIn['id'] ?? 0);
			$userId = (int)($checkIn['userId'] ?? 0);
			$dateCreate = $checkIn['dateCreate'] ?? '';

			if ($checkInId === 0 || $userId === 0 || $dateCreate === '')
			{
				continue;
			}

			$geohash = Geohash::encode($latitude, $longitude);
			$dateCreatePrepared = new \Bitrix\Main\Type\DateTime($dateCreate, 'Y-m-d H:i:s');

			if (AddressQueueRepository::add($checkInId, $geohash, $userId, $dateCreatePrepared, $viewerId))
			{
				$added = true;
			}
		}

		if (!$added)
		{
			return;
		}

		$this->scheduleAgent();
		$this->tryProcessImmediately();
	}

	private function tryProcessImmediately(): bool
	{
		$connection = Application::getConnection();

		if (!$connection->lock(AddressResolveAgent::QUEUE_LOCK_NAME, 0))
		{
			return false;
		}

		try
		{
			return AddressResolveAgent::runOnceLocked();
		}
		finally
		{
			$connection->unlock(AddressResolveAgent::QUEUE_LOCK_NAME);
		}
	}

	private function scheduleAgent(): void
	{
		$agentName = 'Bitrix\\StaffTrack\\Infrastructure\\Agent\\CheckIn\\AddressResolveAgent::run();';

		$existing = \CAgent::GetList(
			[],
			[
				'MODULE_ID' => 'stafftrack',
				'NAME' => $agentName,
			],
		)->Fetch();

		if ($existing)
		{
			return;
		}

		\CAgent::AddAgent(
			$agentName,
			'stafftrack',
			'N',
			0,
			'',
			'Y',
			ConvertTimeStamp(time() + self::FALLBACK_AGENT_DELAY, 'FULL'),
		);
	}
}
