<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Repository\Mapper;

use Bitrix\Intranet\Internal\Entity\IntranetUser;
use Bitrix\Intranet\Internal\Model\EO_IntranetUser;
use Bitrix\Intranet\Internal\Model\IntranetUserTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class IntranetUserMapper
{
	public function convertFromOrm(EO_IntranetUser $ormModel): IntranetUser
	{
		$intranetUser = new IntranetUser();

		$intranetUser
			->setId($ormModel->getId())
			->setUserId($ormModel->getUserId())
			->setInitialized($ormModel->getInitialized())
		;

		return $intranetUser;
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function convertToOrm(IntranetUser $entity): EO_IntranetUser
	{
		$ormIntranetUser = $entity->getId()
			? EO_IntranetUser::wakeUp($entity->getId())
			: IntranetUserTable::createObject();

		$ormIntranetUser
			->setUserId($entity->getUserId())
			->setInitialized($entity->isInitialized())
		;

		return $ormIntranetUser;
	}
}
