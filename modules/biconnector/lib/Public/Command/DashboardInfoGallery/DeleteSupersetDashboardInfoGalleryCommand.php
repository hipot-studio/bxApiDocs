<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfoGallery;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Error;

class DeleteSupersetDashboardInfoGalleryCommand extends AbstractCommand
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
			return (new DeleteSupersetDashboardInfoGalleryCommandHandler())($this);
		}
		catch (\Exception $e)
		{
			return (new SupersetDashboardInfoGalleryResult())->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}
	}
}
