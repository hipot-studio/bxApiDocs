<?php

namespace Bitrix\BIConnector\Public\Command\DashboardView;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardViewRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class DeleteSupersetDashboardViewCommandHandler
{
	private SupersetDashboardViewRepository $repository;
	private SupersetDashboardViewResult $dashboardViewResult;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(SupersetDashboardViewRepository::class);
		$this->dashboardViewResult = new SupersetDashboardViewResult();
	}

	public function setDashboardViewResult(SupersetDashboardViewResult $dashboardViewResult): self
	{
		$this->dashboardViewResult = $dashboardViewResult;
		return $this;
	}

	public function __invoke(DeleteSupersetDashboardViewCommand $command): SupersetDashboardViewResult
	{
		try
		{
			$this->repository->delete($command->id);
		}
		catch (PersistenceException|\Exception $e)
		{
			$this->dashboardViewResult->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}

		return $this->dashboardViewResult;
	}
}
