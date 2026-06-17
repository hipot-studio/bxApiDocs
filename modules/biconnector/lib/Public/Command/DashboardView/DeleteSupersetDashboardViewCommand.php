<?php

namespace Bitrix\BIConnector\Public\Command\DashboardView;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Error;

class DeleteSupersetDashboardViewCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $id
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new DeleteSupersetDashboardViewCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new SupersetDashboardViewResult())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
