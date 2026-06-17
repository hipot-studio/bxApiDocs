<?php

namespace Bitrix\BIConnector\Internal\Repository\Mapper;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfo;
use Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardInfo;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardInfoTable;

class SupersetDashboardInfoMapper
{
	public function convertFromOrm(EO_SupersetDashboardInfo $ormModel): SupersetDashboardInfo
	{
		$dashboardInfo = new SupersetDashboardInfo(
			$ormModel->getDashboardId(),
			$ormModel->getPublishedById(),
			$ormModel->getPublishedDate(),
			$ormModel->getUpdatedById(),
			$ormModel->getUpdatedDate(),
			$ormModel->getDescription(),
			$ormModel->getImageId(),
		);

		$dashboardInfo->setId($ormModel->getId());

		return $dashboardInfo;
	}

	public function convertToOrm(SupersetDashboardInfo $entity): EO_SupersetDashboardInfo
	{
		$ormModel = $entity->getId()
			? EO_SupersetDashboardInfo::wakeUp($entity->getId())
			: SupersetDashboardInfoTable::createObject()->setDashboardId($entity->getDashboardId())
		;

		$ormModel
			->setPublishedById($entity->getPublishedById())
			->setPublishedDate($entity->getPublishedDate())
			->setUpdatedById($entity->getUpdatedById())
			->setUpdatedDate($entity->getUpdatedDate())
			->setDescription($entity->getDescription())
			->setImageId($entity->getImageId())
		;

		return $ormModel;
	}
}
