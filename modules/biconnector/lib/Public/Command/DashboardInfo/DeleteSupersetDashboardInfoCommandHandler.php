<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfo;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class DeleteSupersetDashboardInfoCommandHandler
{
	private SupersetDashboardInfoRepository $repository;
	private SupersetDashboardInfoResult $dashboardInfoResult;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(SupersetDashboardInfoRepository::class);
		$this->dashboardInfoResult = new SupersetDashboardInfoResult();
	}

	public function __invoke(DeleteSupersetDashboardInfoCommand $command): SupersetDashboardInfoResult
	{
		try
		{
			$this->repository->delete($command->id);
		}
		catch (PersistenceException|\Exception $e)
		{
			$this->dashboardInfoResult->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}

		return $this->dashboardInfoResult;
	}
}
