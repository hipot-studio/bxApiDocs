<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Command\DashboardChat;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class SyncDashboardDiscussionChatAccessCommand extends AbstractCommand
{
	public function __construct(public readonly ?array $dashboardIds = null)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new SyncDashboardDiscussionChatAccessCommandHandler())($this);
		}
		catch (\Throwable $exception)
		{
			return (new DashboardDiscussionChatAccessSyncResult())->addError(
				new Error($exception->getMessage(), (string)$exception->getCode()),
			);
		}
	}
}
