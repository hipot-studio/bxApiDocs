<?php

namespace Bitrix\BIConnector\Public\Command\ShareControl;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Lifts the ban only: links switched off by the ban stay off until their authors switch them on.
 */
class UnbanDashboardCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $dashboardId,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		try
		{
			/** @var SupersetDashboardShareBanRepository $banRepository */
			$banRepository = ServiceLocator::getInstance()->get('biconnector.repository.shareBan');
			$banRepository->remove($this->dashboardId);
		}
		catch (\Exception $e)
		{
			$result->addError(new Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}
}
