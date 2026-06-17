<?php

namespace Bitrix\BIConnector\Internal\Repository\Mapper;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfoGallery;
use Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfoGallery;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoGalleryTable;

class SupersetDashboardInfoGalleryMapper
{
	public function convertFromOrm(EO_SupersetDashboardInfoGallery $ormModel): SupersetDashboardInfoGallery
	{
		$galleryItem = new SupersetDashboardInfoGallery(
			$ormModel->getDashboardInfoId(),
			$ormModel->getImageId(),
			$ormModel->getSort()
		);

		$galleryItem->setId($ormModel->getId());

		return $galleryItem;
	}

	public function convertToOrm(SupersetDashboardInfoGallery $entity): EO_SupersetDashboardInfoGallery
	{
		$ormModel = $entity->getId()
			? EO_SupersetDashboardInfoGallery::wakeUp($entity->getId())
			: SupersetDashboardInfoGalleryTable::createObject()->setDashboardInfoId($entity->getDashboardInfoId())
		;

		$ormModel
			->setImageId($entity->getImageId())
			->setSort($entity->getSort())
		;

		return $ormModel;
	}
}
