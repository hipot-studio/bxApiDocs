<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfo;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Type\DateTime;

class AddSupersetDashboardInfoCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $dashboardId,
		public readonly ?int $publishedById = null,
		public readonly ?DateTime $publishedDate = null,
		public readonly ?int $updatedById = null,
		public readonly ?DateTime $updatedDate = null,
		public readonly ?string $description = null,
		public readonly ?int $imageId = null
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new AddSupersetDashboardInfoCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new SupersetDashboardInfoResult())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
