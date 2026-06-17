<?php

namespace Bitrix\BIConnector\Public\Command\DashboardView;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardViewRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\ArgumentException;

class UpdateSupersetDashboardViewCommandHandler
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
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function __invoke(UpdateSupersetDashboardViewCommand $command): SupersetDashboardViewResult
	{
		try
		{
			$dashboardView = $this->repository->getById($command->id);

			if (!$dashboardView)
			{
				$this->dashboardViewResult->addError(new Error('Dashboard view not found.'));

				return $this->dashboardViewResult;
			}

			$dashboardView
				->setUserId($command->userId)
				->setViewedAt($command->viewedAt)
			;

			$this->repository->save($dashboardView);
			$this->dashboardViewResult->setDashboardView($dashboardView);
		}
		catch (PersistenceException|\Exception $e)
		{
			$this->dashboardViewResult->addError(
				new Error($e->getMessage(), $e->getCode()),
			);
		}

		return $this->dashboardViewResult;
	}
}
