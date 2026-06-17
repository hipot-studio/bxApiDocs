<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Debugger\Debug;
use Bitrix\Bizproc\Internal\Model\Debugger\DebugTable;
use Bitrix\Bizproc\Internal\Model\Debugger\EO_Debug;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;

final class DebugOrmMapper
{
	public static function getFieldsMap(): array
	{
		return [
			'ID' => 'id',
			'USER_ID' => 'userId',
			'TEMPLATE_ID' => 'templateId',
			'MODULE_ID' => 'moduleId',
			'ENTITY' => 'entity',
			'DOCUMENT_ID' => 'documentId',
			'ENABLED' => 'enabled',
			'CREATED_AT' => 'createdAt',
			'UPDATED_AT' => 'updatedAt',
		];
	}

	/**
	 * @throws SystemException
	 */
	public function convertFromOrm(EO_Debug $ormModel): Debug
	{
		return Debug::mapFromArray([
			'id' => $ormModel->getId(),
			'user_id' => $ormModel->getUserId(),
			'template_id' => $ormModel->getTemplateId(),
			'module_id' => $ormModel->getModuleId(),
			'entity' => $ormModel->getEntity(),
			'document_id' => $ormModel->getDocumentId(),
			'enabled' => $ormModel->getEnabled(),
			'created_at' => $ormModel->getCreatedAt(),
			'updated_at' => $ormModel->getUpdatedAt(),
		]);
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function convertToOrm(Debug $entity): EO_Debug
	{
		$ormModel = !$entity->isNew()
			? EO_Debug::wakeUp($entity->getId())
			: DebugTable::createObject();

		$ormModel
			->setUserId($entity->getUserId())
			->setTemplateId($entity->getTemplateId())
			->setEnabled($entity->isEnabled() ? 'Y' : 'N')
		;

		$documentId = $entity->getDocumentId();
		if ($documentId !== null)
		{
			$ormModel->setModuleId($documentId->moduleId);
			$ormModel->setEntity($documentId->entity);
			$ormModel->setDocumentId($documentId->documentId);
		}
		else
		{
			$ormModel->setModuleId(null);
			$ormModel->setEntity(null);
			$ormModel->setDocumentId(null);
		}

		return $ormModel;
	}

	/**
	 * Convert ORM model data to array format for entity mapping
	 *
	 * @param array $row Raw database row
	 * @return Debug
	 */
	public function convertFromArray(array $row): Debug
	{
		return Debug::mapFromArray([
			'id' => $row['ID'] ?? null,
			'user_id' => $row['USER_ID'] ?? null,
			'template_id' => $row['TEMPLATE_ID'] ?? null,
			'module_id' => $row['MODULE_ID'] ?? null,
			'entity' => $row['ENTITY'] ?? null,
			'document_id' => $row['DOCUMENT_ID'] ?? null,
			'enabled' => $row['ENABLED'] ?? 'N',
			'created_at' => $row['CREATED_AT'] ?? null,
			'updated_at' => $row['UPDATED_AT'] ?? null,
		]);
	}
}
