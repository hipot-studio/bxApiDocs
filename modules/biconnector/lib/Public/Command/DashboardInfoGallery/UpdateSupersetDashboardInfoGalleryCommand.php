<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfoGallery;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Error;

class UpdateSupersetDashboardInfoGalleryCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $id,
		public readonly int $imageId,
		public readonly ?int $sort = null
	)
	{
	}

	protected function execute(): Result
	{
		try
		{
			return (new UpdateSupersetDashboardInfoGalleryCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new SupersetDashboardInfoGalleryResult())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
