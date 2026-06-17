<?php

namespace Bitrix\BIConnector\Internal\Repository\Mapper;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardView;
use Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardView;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardViewTable;

class SupersetDashboardViewMapper
{
	public function convertFromOrm(EO_SupersetDashboardView $ormModel): SupersetDashboardView
	{
		$dashboardView = new SupersetDashboardView(
			$ormModel->getDashboardId(),
			$ormModel->getUserId(),
			$ormModel->getViewedAt()
		);

		$dashboardView->setId($ormModel->getId());

		return $dashboardView;
	}

	public function convertToOrm(SupersetDashboardView $entity): EO_SupersetDashboardView
	{
		$ormModel = $entity->getId()
			? EO_SupersetDashboardView::wakeUp($entity->getId())
			: SupersetDashboardViewTable::createObject()->setDashboardId($entity->getDashboardId())
		;

		$ormModel
			->setUserId($entity->getUserId())
			->setViewedAt($entity->getViewedAt())
		;

		return $ormModel;
	}
}
