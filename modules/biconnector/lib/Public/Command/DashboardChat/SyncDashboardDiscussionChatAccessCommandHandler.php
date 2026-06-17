<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Command\DashboardChat;

use Bitrix\BIConnector\Internal\Integration\Im\DashboardDiscussionChatAccessSyncService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;

class SyncDashboardDiscussionChatAccessCommandHandler
{
	private DashboardDiscussionChatAccessSyncService $accessSyncService;

	public function __construct()
	{
		$this->accessSyncService = ServiceLocator::getInstance()->get('biconnector.service.dashboardDiscussionChatAccessSync');
	}

	public function __invoke(SyncDashboardDiscussionChatAccessCommand $command): DashboardDiscussionChatAccessSyncResult
	{
		$syncResult = $command->dashboardIds === null
			? $this->accessSyncService->syncAll()
			: $this->accessSyncService->syncByDashboardIds($command->dashboardIds)
		;

		return $this->createResult($syncResult);
	}

	private function createResult(Result $syncResult): DashboardDiscussionChatAccessSyncResult
	{
		$result = new DashboardDiscussionChatAccessSyncResult();
		if (!$syncResult->isSuccess())
		{
			$result->addErrors($syncResult->getErrors());
		}

		$result->setData($syncResult->getData());

		return $result;
	}
}
