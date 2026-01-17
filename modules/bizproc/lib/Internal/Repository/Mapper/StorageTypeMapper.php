<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\StorageType\StorageType;
use Bitrix\Bizproc\Internal\Model\StorageTypeTable;
use Bitrix\Bizproc\Internal\Model\EO_StorageType;
use Bitrix\Main\Type\DateTime;

class StorageTypeMapper
{
	public function convertFromOrm(EO_StorageType $ormModel): StorageType
	{
		$storageType = new StorageType();

		$storageType
			->setId($ormModel->getId())
			->setTitle($ormModel->getTitle())
			->setDescription($ormModel->getDescription())
			->setCode($ormModel->getCode())
			->setCreatedBy($ormModel->getCreatedBy())
			->setUpdatedBy($ormModel->getUpdatedBy())
			->setCreatedAt($ormModel->getCreatedTime()?->getTimestamp())
			->setUpdatedAt($ormModel->getUpdatedTime()?->getTimestamp())
		;

		return $storageType;
	}

	public function convertToOrm(StorageType $entity): EO_StorageType
	{
		$ormModel = !$entity->isNew()
			? EO_StorageType::wakeUp($entity->getId())
			: StorageTypeTable::createObject()
		;

		if ($entity->isNew())
		{
			$ormModel
				->setCreatedBy($entity->getCreatedBy())
				->setCreatedTime(new DateTime())
			;
		}

		$ormModel
			->setUpdatedBy($entity->getUpdatedBy())
			->setUpdatedTime(new DateTime())
			->setTitle($entity->getTitle())
			->setDescription($entity->getDescription())
		;

		if ($entity->getCode())
		{
			$ormModel->setCode($entity->getCode());
		}

		return $ormModel;
	}

	public static function getFieldsMap(): array
	{
		return [
			'ID' => 'id',
			'TITLE' => 'title',
			'DESCRIPTION' => 'description',
			'CODE' => 'code',
			'CREATED_BY' => 'createdBy',
			'UPDATED_BY' => 'updatedBy',
			'CREATED_TIME' => 'createdAt',
			'UPDATED_TIME' => 'updatedAt',
		];
	}
}
