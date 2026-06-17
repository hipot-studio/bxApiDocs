<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfoGallery;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Error;

class AddSupersetDashboardInfoGalleryCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $dashboardInfoId,
		public readonly int $imageId,
		public readonly ?int $sort = null
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new AddSupersetDashboardInfoGalleryCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new SupersetDashboardInfoGalleryResult())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
