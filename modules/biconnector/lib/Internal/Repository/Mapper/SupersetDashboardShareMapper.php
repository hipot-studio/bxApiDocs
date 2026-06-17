<?php

namespace Bitrix\BIConnector\Internal\Repository\Mapper;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareTable;

class SupersetDashboardShareMapper
{
	public function convertFromOrm(EO_SupersetDashboardShare $ormModel): SupersetDashboardShare
	{
		$entity = new SupersetDashboardShare(
			$ormModel->getDashboardId(),
			$ormModel->getToken(),
			$ormModel->getPassword(),
			$ormModel->getDateExpire(),
			$ormModel->getActive(),
			$ormModel->getCreatedById(),
			$ormModel->getDateCreate(),
			$ormModel->getDateModify(),
			$ormModel->getExternalFilterValues(),
			$ormModel->getUrlParameterValues(),
			$ormModel->getLoginAttempts(),
			$ormModel->getLoginLockedTill(),
		);

		$entity->setId($ormModel->getId());

		return $entity;
	}

	public function convertToOrm(SupersetDashboardShare $entity): EO_SupersetDashboardShare
	{
		$ormModel = $entity->getId()
			? EO_SupersetDashboardShare::wakeUp($entity->getId())
			: SupersetDashboardShareTable::createObject()
				->setDashboardId($entity->getDashboardId())
				->setToken($entity->getToken())
				->setCreatedById($entity->getCreatedById())
		;

		$ormModel
			->setPassword($entity->getPassword())
			->setDateExpire($entity->getDateExpire())
			->setActive($entity->getActive())
			->setDateModify($entity->getDateModify())
			->setExternalFilterValues($entity->getExternalFilterValues())
			->setUrlParameterValues($entity->getUrlParameterValues())
			->setLoginAttempts($entity->getLoginAttempts())
			->setLoginLockedTill($entity->getLoginLockedTill())
		;

		return $ormModel;
	}
}
