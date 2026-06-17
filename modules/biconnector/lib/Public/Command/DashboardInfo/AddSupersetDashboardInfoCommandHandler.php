<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfo;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfo;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class AddSupersetDashboardInfoCommandHandler
{
	private SupersetDashboardInfoRepository $repository;
	private SupersetDashboardInfoResult $dashboardInfoResult;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(SupersetDashboardInfoRepository::class);
		$this->dashboardInfoResult = new SupersetDashboardInfoResult();
	}

	public function __invoke(AddSupersetDashboardInfoCommand $command): SupersetDashboardInfoResult
	{
		try
		{
			$dashboardInfo = new SupersetDashboardInfo($command->dashboardId);
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
