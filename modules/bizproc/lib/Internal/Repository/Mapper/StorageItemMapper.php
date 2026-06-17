<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Main\Type\DateTime;
use Bitrix\Bizproc\Internal\Model\EO_StorageRecordData;

class StorageItemMapper
{
	public function convertFromOrm(EO_StorageRecordData $ormModel): StorageItem
	{
		$storageItem = new StorageItem();

		$storageItem
			->setId($ormModel->getId())
			->setStorageId($ormModel->getStorageId())
			->setCreatedBy($ormModel->getCreatedBy())
			->setUpdatedBy($ormModel->getUpdatedBy())
			->setCreatedAt($ormModel->getCreatedTime()?->getTimestamp())
			->setUpdatedAt($ormModel->getUpdatedTime()?->getTimestamp())
			->setDocumentId($ormModel->getDocumentId())
			->setWorkflowId($ormModel->getWorkflowId())
			->setTemplateId($ormModel->getTemplateId())
		;

		return $storageItem;
	}

	public function convertToOrm(int $storageTypeId, StorageItem $entity): ?EO_StorageRecordData
	{
		$dataManager = Container::getStorageRecordDataManager();

		$ormModel = !$entity->isNew()
			? $dataManager::getEntity()->wakeUpObject($entity->getId())
			: $dataManager::createObject();

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
			->setDocumentId($entity->getDocumentId())
			->setWorkflowId($entity->getWorkflowId())
			->setTemplateId($entity->getTemplateId())
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
