<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class DeactivateShareCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $dashboardId,
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new DeactivateShareCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new Result())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
