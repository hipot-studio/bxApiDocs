<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;
use Bitrix\Bizproc\Internal\Model\Debugger\DebugSessionTable;
use Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugSession;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;

final class DebugSessionOrmMapper
{
	public static function getFieldsMap(): array
	{
		return [
			'ID' => 'id',
			'USER_ID' => 'userId',
			'DEBUG_ID' => 'debugId',
			'MODULE_ID' => 'moduleId',
			'ENTITY' => 'entity',
			'DOCUMENT_ID' => 'documentId',
			'WORKFLOW_ID' => 'workflowId',
			'TEMPLATE_ID' => 'templateId',
			'START_TIME' => 'startTime',
			'END_TIME' => 'endTime',
			'METADATA' => 'metadata',
			'LOGS' => 'logs',
			'METRICS' => 'metrics',
		];
	}

	public function convertFromOrm(EO_DebugSession $ormModel): DebugSession
	{
		return DebugSession::mapFromArray([
			'id' => $ormModel->getId(),
			'user_id' => $ormModel->getUserId(),
			'debug_id' => $ormModel->getDebugId(),
			'module_id' => $ormModel->getModuleId(),
			'entity' => $ormModel->getEntity(),
			'document_id' => $ormModel->getDocumentId(),
			'workflow_id' => $ormModel->getWorkflowId(),
			'template_id' => $ormModel->getTemplateId(),
			'start_time' => $ormModel->getStartTime(),
			'end_time' => $ormModel->getEndTime(),
			'metadata' => $ormModel->getMetadata(),
			'logs' => $ormModel->getLogs(),
			'metrics' => $ormModel->getMetrics(),
		]);
	}

	/**
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function convertToOrm(DebugSession $entity): EO_DebugSession
	{
		$ormModel = !$entity->isNew()
			? EO_DebugSession::wakeUp($entity->getId())
			: DebugSessionTable::createObject();

		$ormModel
			->setUserId($entity->getUserId())
			->setStartTime($entity->getStartTime()?->getValue())
			->setEndTime($entity->getEndTime()?->getValue())
			->setMetadata(Json::encode($entity->getMetadata()))
			->setLogs(Json::encode($entity->getLogs()->toArray()))
			->setMetrics(Json::encode($entity->getMetrics()->toArray()))
		;

		if ($entity->getDebugId() !== null)
		{
			$ormModel->setDebugId($entity->getDebugId());
		}

		$documentId = $entity->getDocumentId();
		if ($documentId !== null)
		{
			$ormModel->setModuleId($documentId->moduleId);
			$ormModel->setEntity($documentId->entity);
			$ormModel->setDocumentId($documentId->documentId);
		}

		if ($entity->getWorkflowId() !== null)
		{
			$ormModel->setWorkflowId($entity->getWorkflowId());
		}

		if ($entity->getTemplateId() !== null)
		{
			$ormModel->setTemplateId($entity->getTemplateId());
		}

		return $ormModel;
	}
}
