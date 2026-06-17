<?php

namespace Bitrix\BIConnector\Public\Command\DashboardChat;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class DeleteSupersetDashboardChatCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $id,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new DeleteSupersetDashboardChatCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new SupersetDashboardChatResult())->addError(
				new Error($e->getMessage(), $e->getCode()),
			);
		}
	}
}
