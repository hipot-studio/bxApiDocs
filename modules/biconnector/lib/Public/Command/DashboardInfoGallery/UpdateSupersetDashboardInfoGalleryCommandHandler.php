<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfoGallery;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoGalleryRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class UpdateSupersetDashboardInfoGalleryCommandHandler
{
	private SupersetDashboardInfoGalleryRepository $repository;
	private SupersetDashboardInfoGalleryResult $result;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(SupersetDashboardInfoGalleryRepository::class);
		$this->result = new SupersetDashboardInfoGalleryResult();
	}

	public function __invoke(UpdateSupersetDashboardInfoGalleryCommand $command): SupersetDashboardInfoGalleryResult
	{
		try
		{
			$galleryItem = $this->repository->getById($command->id);

			if (!$galleryItem)
			{
				$this->result->addError(new Error('Gallery item not found.'));
				return $this->result;
			}

			$galleryItem->setImageId($command->imageId);

			if ($command->sort !== null)
			{
				$galleryItem->setSort($command->sort);
			}

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
