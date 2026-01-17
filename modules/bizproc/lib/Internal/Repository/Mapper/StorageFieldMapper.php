<?php

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\StorageField\StorageField;
use Bitrix\Bizproc\Internal\Model\StorageFieldTable;
use Bitrix\Bizproc\Internal\Model\EO_StorageField;

class StorageFieldMapper
{
	public function convertFromOrm(EO_StorageField $ormModel): StorageField
	{
		$storageField = new StorageField();

		$storageField
			->setId($ormModel->getId())
			->setStorageId($ormModel->getStorageId())
			->setCode($ormModel->getCode())
			->setSort($ormModel->getSort())
			->setName($ormModel->getName())
			->setDescription($ormModel->getDescription())
			->setType($ormModel->getType())
			->setMultiple($ormModel->getMultiple())
			->setMandatory($ormModel->getMandatory())
			->setSettings($ormModel->getSettings());

		return $storageField;
	}

	public function convertToOrm(StorageField $entity): EO_StorageField
	{
		$ormModel = !$entity->isNew()
			? EO_StorageField::wakeUp($entity->getId())
			: StorageFieldTable::createObject();

		if ($entity->isNew())
		{
			$ormModel
				->setType($entity->getType())
				->setStorageId($entity->getStorageId())
				->setCode($entity->getCode())
			;
		}

		$ormModel
			->setSort($entity->getSort())
			->setName($entity->getName())
			->setDescription($entity->getDescription())
			->setMultiple($entity->getMultiple())
			->setMandatory($entity->getMandatory())
			->setSettings($entity->getSettings());

		return $ormModel;
	}

	public static function getFieldsMap(): array
	{
		return [
			'ID' => 'id',
			'STORAGE_ID' => 'storageId',
			'CODE' => 'code',
			'SORT' => 'sort',
			'NAME' => 'name',
			'DESCRIPTION' => 'description',
			'TYPE' => 'type',
			'MULTIPLE' => 'multiple',
			'MANDATORY' => 'mandatory',
			'SETTINGS' => 'settings',
		];
	}
}
