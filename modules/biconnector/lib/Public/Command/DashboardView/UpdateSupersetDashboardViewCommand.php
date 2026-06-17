<?php

namespace Bitrix\BIConnector\Public\Command\DashboardView;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Type\DateTime;

class UpdateSupersetDashboardViewCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly DateTime $viewedAt
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new UpdateSupersetDashboardViewCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new SupersetDashboardViewResult())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
