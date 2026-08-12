<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Public\Command\Common\ICloud;

use Bitrix\Calendar\Integration\Pull\PushCommand;
use Bitrix\Calendar\Sync\Connection\Connection;
use Bitrix\Calendar\Sync\Handlers\MasterPushHandler;
use Bitrix\Calendar\Core\Mappers\Factory;
use Bitrix\Calendar\Sync\Icloud\Helper;
use Bitrix\Calendar\Sync\Managers\NotificationManager;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\ICloud\ICloudEventSynchronizer;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\ICloud\ICloudSectionSynchronizer;
use Bitrix\Calendar\Synchronization\Internal\Service\Logger\RequestLogger;
use Bitrix\Calendar\Synchronization\Internal\Exception\Exception;
use Bitrix\Calendar\Util;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use CCalendar;
use Throwable;

class SynchronizeConnectionCommandHandler
{
	public function __construct(
		private readonly ICloudSectionSynchronizer $sectionSynchronizer,
		private readonly ICloudEventSynchronizer $eventSynchronizer,
		private readonly Factory $mapperFactory,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws Exception
	 */
	public function __invoke(SynchronizeConnectionCommand $command): ?Connection
	{
		$connection = $this->mapperFactory->getConnection()->getById($command->connectionId);

		if (!$connection)
		{
			throw new Exception('Connection not found');
		}

		$userId = $command->userId;

		// Serialize import of a single connection to eliminate the race of overlapping
		// initial imports (duplicate events). Export stays outside the lock - it only
		// enqueues a message; the actual write happens in a separate consumer.
		//
		// Fail-fast lock (default timeout 0): if the connection is already being imported by
		// another process, skip the import instead of waiting. That other process is importing the
		// very same connection right now and emits its own pull event on completion, so the client
		// still refreshes; waiting here would only redo an idempotent import and hold the worker.
		$lockName = Helper::getImportLockName($command->connectionId);

		try
		{
			if (Application::getConnection()->lock($lockName))
			{
				try
				{
					$this->sectionSynchronizer->importSections($userId);

					$this->eventSynchronizer->importEvents($userId);
				}
				finally
				{
					Application::getConnection()->unlock($lockName);
				}
			}
			else
			{
				// Skipped: another process holds the lock and is importing this very connection
				// right now (it will complete the import). We intentionally still emit the stage
				// pull events and the finished notification below: they drive the first-connection
				// wizard, which ignores the holder's refresh_sync_status event while in wizard mode
				// (see calendar/install/js/calendar/sync/manager - handlePullEvent), so suppressing
				// them would stall the wizard when the agent is the lock holder.
				$this->logImportSkipped($connection);
			}

			$this->addPullEvent($connection, MasterPushHandler::MASTER_STAGE[2]);

			$this->sectionSynchronizer->exportSections($userId);

			$this->eventSynchronizer->exportEvents($userId);

			$this->addPullEvent($connection, MasterPushHandler::MASTER_STAGE[3]);
		}
		catch (Throwable $e)
		{
			throw new Exception(
				'Unable to sync connection for iCloud: ' . $e->getMessage(),
				$e->getCode(),
				$e,
			);
		}

		CCalendar::ClearCache();

		NotificationManager::addFinishedSyncNotificationAgent(
			$userId,
			Helper::ACCOUNT_TYPE,
		);

		return $connection;
	}

	private function logImportSkipped(Connection $connection): void
	{
		$logger = ServiceLocator::getInstance()->get(RequestLogger::class);
		$logger
			->setType($connection->getVendor()->getCode())
			->setEntityId($connection->getId())
			->setUserId((int)$connection->getOwner()?->getId())
		;

		$logger->debug('Import of connection ' . $connection->getName() . ' skipped: already locked');
	}

	private function addPullEvent(Connection $connection, string $stage): void
	{
		Util::addPullEvent(
			PushCommand::ProcessSyncConnection,
			(int)$connection->getOwner()?->getId(),
			[
				'vendorName' => Helper::ACCOUNT_TYPE,
				'accountName' => $connection->getServer()->getUserName(),
				'stage' => $stage,
			],
		);
	}
}
