<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfoGallery;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoGalleryRepository;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

class DeleteSupersetDashboardInfoGalleryCommandHandler
{
	private SupersetDashboardInfoGalleryRepository $repository;
	private SupersetDashboardInfoGalleryResult $result;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(SupersetDashboardInfoGalleryRepository::class);
		$this->result = new SupersetDashboardInfoGalleryResult();
	}

	public function __invoke(DeleteSupersetDashboardInfoGalleryCommand $command): SupersetDashboardInfoGalleryResult
	{
		try
		{
			$this->repository->delete($command->id);
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
