<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class CreateShareCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $dashboardId,
		public readonly int $createdById,
		public readonly string $password,
		public readonly DateTime $dateExpire,
		public readonly ?array $externalFilterValues = null,
		public readonly ?array $urlParameterValues = null,
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new CreateShareCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new CreateShareResult())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
