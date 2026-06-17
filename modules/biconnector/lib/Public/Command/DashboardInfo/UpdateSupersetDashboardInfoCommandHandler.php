<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfo;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class UpdateSupersetDashboardInfoCommandHandler
{
	private SupersetDashboardInfoRepository $repository;
	private SupersetDashboardInfoResult $dashboardInfoResult;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(SupersetDashboardInfoRepository::class);
		$this->dashboardInfoResult = new SupersetDashboardInfoResult();
	}

	public function __invoke(UpdateSupersetDashboardInfoCommand $command): SupersetDashboardInfoResult
	{
		try
		{
			$dashboardInfo = $this->repository->getById($command->id);

			if (!$dashboardInfo)
			{
				$this->dashboardInfoResult->addError(new Error('Dashboard info not found.'));
				return $this->dashboardInfoResult;
			}

			$dashboardInfo
				->setPublishedById($command->publishedById)
				->setPublishedDate($command->publishedDate)
				->setUpdatedById($command->updatedById)
				->setUpdatedDate($command->updatedDate)
				->setDescription($command->description)
				->setImageId($command->imageId)
			;

			$this->repository->save($dashboardInfo);
			$this->dashboardInfoResult->setDashboardInfo($dashboardInfo);
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
