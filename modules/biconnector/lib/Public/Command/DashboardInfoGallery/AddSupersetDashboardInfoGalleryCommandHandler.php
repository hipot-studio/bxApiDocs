<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfoGallery;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfoGallery;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoGalleryRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class AddSupersetDashboardInfoGalleryCommandHandler
{
	private SupersetDashboardInfoGalleryRepository $repository;
	private SupersetDashboardInfoGalleryResult $result;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(SupersetDashboardInfoGalleryRepository::class);
		$this->result = new SupersetDashboardInfoGalleryResult();
	}

	public function __invoke(AddSupersetDashboardInfoGalleryCommand $command): SupersetDashboardInfoGalleryResult
	{
		try
		{
			$galleryItem = new SupersetDashboardInfoGallery(
				$command->dashboardInfoId,
				$command->imageId,
				$command->sort ?? 500
			);

			$this->repository->save($galleryItem);
			$this->result->setGalleryItem($galleryItem);
		}
		catch (PersistenceException|\Exception $e)
		{
			$this->result->addError(
				new Error($e->getMessage(), $e->getCode())
			);
		}

		return $this->result;
	}
}
