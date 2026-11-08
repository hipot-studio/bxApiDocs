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
			$ormModel->getDisabledById(),
			$ormModel->getDateDisabled(),
			$ormModel->getDateLastOpened(),
		);

		$entity->setId($ormModel->getId());

		return $entity;
	}

	/**
	 * Guest columns (LOGIN_*, DATE_LAST_OPENED) are left out: the guest path writes them with its own
	 * point updates, and a full save must not overwrite them with a stale copy.
	 */
	public function convertToOrm(SupersetDashboardShare $entity): EO_SupersetDashboardShare
	{
		$ormModel = $entity->getId()
			? EO_SupersetDashboardShare::wakeUp($entity->getId())
			: SupersetDashboardShareTable::createObject()
				->setDashboardId($entity->getDashboardId())
				->setCreatedById($entity->getCreatedById())
		;

		$ormModel
			->setToken($entity->getToken())
			->setPassword($entity->getPassword())
			->setDateExpire($entity->getDateExpire())
			->setActive($entity->getActive())
			->setDateModify($entity->getDateModify())
			->setExternalFilterValues($entity->getExternalFilterValues())
			->setUrlParameterValues($entity->getUrlParameterValues())
			->setDisabledById($entity->getDisabledById())
			->setDateDisabled($entity->getDateDisabled())
		;

		if ($entity->getDateCreate())
		{
			$ormModel->setDateCreate($entity->getDateCreate());
		}

		return $ormModel;
	}
}
