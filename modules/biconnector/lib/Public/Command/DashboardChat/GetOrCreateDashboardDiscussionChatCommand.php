<?php

namespace Bitrix\BIConnector\Public\Command\DashboardChat;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class GetOrCreateDashboardDiscussionChatCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $dashboardId,
		public readonly string $dashboardTitle,
		public readonly int $currentUserId,
		public readonly int $dashboardCreatedById = 0,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new GetOrCreateDashboardDiscussionChatCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new DashboardDiscussionChatResult())->addError(
				new Error($e->getMessage(), $e->getCode()),
			);
		}
	}
}
