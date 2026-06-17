<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfo;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Error;

class DeleteSupersetDashboardInfoCommand extends AbstractCommand
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
			return (new DeleteSupersetDashboardInfoCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new SupersetDashboardInfoResult())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
