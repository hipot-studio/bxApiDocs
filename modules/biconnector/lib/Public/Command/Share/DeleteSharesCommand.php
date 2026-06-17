<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class DeleteSharesCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $dashboardId,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new DeleteSharesCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new Result())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
