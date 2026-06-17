<?php

namespace Bitrix\BIConnector\Public\Command\DashboardView;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardView;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardViewRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Type\DateTime;

class AddSupersetDashboardViewCommandHandler
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

	/**
	 * @throws PersistenceException
	 */
	public function __invoke(AddSupersetDashboardViewCommand $command): SupersetDashboardViewResult
	{
		try
		{
			$dashboardView = new SupersetDashboardView(
				$command->dashboardId,
				$command->userId,
				$command->viewedAt ?? new DateTime()
			);

			$this->repository->save($dashboardView);
			$this->dashboardViewResult->setDashboardView($dashboardView);
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
