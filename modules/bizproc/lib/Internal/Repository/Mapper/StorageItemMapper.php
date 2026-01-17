<?php

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Internal\Model\StorageRecordTable;
use Bitrix\Bizproc\Internal\Model\EO_StorageRecord;
use Bitrix\Main\Type\DateTime;

class StorageItemMapper
{
	public function convertFromOrm(EO_StorageRecord $ormModel): StorageItem
	{
		$storageType = new StorageItem();

		$storageType
			->setId($ormModel->getId())
			->setStorageId($ormModel->getStorageId())
			->setCreatedBy($ormModel->getCreatedBy())
			->setUpdatedBy($ormModel->getUpdatedBy())
			->setCreatedAt($ormModel->getCreatedTime()?->getTimestamp())
			->setUpdatedAt($ormModel->getUpdatedTime()?->getTimestamp())
			->setCode($ormModel->getCode())
			->setDocumentId($ormModel->getDocumentId())
			->setWorkflowId($ormModel->getWorkflowId())
			->setTemplateId($ormModel->getTemplateId())
		;

		return $storageType;
	}

	public function convertToOrm(int $storageTypeId, StorageItem $entity): ?EO_StorageRecord
	{
		$ormModel = !$entity->isNew()
			? EO_StorageRecord::wakeUp($entity->getId())
			: StorageRecordTable::createObject();

		if ($entity->isNew())
		{
			$ormModel
				->setCreatedBy($entity->getCreatedBy())
				->setStorageId($entity->getStorageId())
				->setCreatedTime(new DateTime())
			;
		}

		$ormModel
			->setUpdatedBy($entity->getUpdatedBy())
			->setUpdatedTime(new DateTime())
			->setCode($entity->getCode())
			->setDocumentId($entity->getDocumentId())
			->setWorkflowId($entity->getWorkflowId())
			->setTemplateId($entity->getTemplateId())
			->setValue($entity->getValueFields())
		;

		return $ormModel;
	}

	public static function getFieldsMap(): array
	{
		return [
			'ID' => 'id',
			'CREATED_BY' => 'createdBy',
			'UPDATED_BY' => 'updatedBy',
			'CREATED_TIME' => 'createdAt',
			'UPDATED_TIME' => 'updatedAt',
			'STORAGE_ID' => 'storageId',
			'DOCUMENT_ID' => 'documentId',
			'WORKFLOW_ID' => 'workflowId',
			'TEMPLATE_ID' => 'templateId',
			'CODE' => 'code',
		];
	}
}
