<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Main;
use Bitrix\Rest\Internal\Model\AccessPermissionTable;
use Bitrix\Rest\Internal\Model\EO_AccessPermission;
use Bitrix\Rest\Internal\Entity\Access\AccessPermission;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Model\EO_AccessPermission_Collection;

class AccessPermissionMapper
{
	public static function convertFromOrm(EO_AccessPermission $ormModel): AccessPermission
	{
		return new AccessPermission(
			entityType: EntityType::from($ormModel->getEntityType()),
			accessCode: $ormModel->getAccessCode(),
			permission: PermissionType::from($ormModel->getPermission()),
			id: $ormModel->getId(),
		);
	}

	public static function convertToOrm(AccessPermission $entity): EO_AccessPermission
	{
		/* @var EO_AccessPermission */
		$ormModel = $entity->getId()
			? EO_AccessPermission::wakeUp($entity->getId())
			: AccessPermissionTable::createObject()
		;

		$ormModel
			->setId($entity->getId())
			->setEntityType($entity->getEntityType()->value)
			->setAccessCode($entity->getAccessCode())
			->setPermission($entity->getPermission()->value)
		;

		return $ormModel;
	}

	public function convertCollectionFromOrm(EO_AccessPermission_Collection $ormCollection): Main\Entity\EntityCollection
	{
		$collection = new Main\Entity\EntityCollection();
		foreach ($ormCollection as $ormModel)
		{
			$collection->add($this->convertFromOrm($ormModel));
		}
		return $collection;
	}
}
