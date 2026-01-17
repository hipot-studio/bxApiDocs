<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Document;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Integration\Rag\DocumentFieldTypes\RagKnowledgeBaseType;
use Bitrix\Bizproc\Internal\Integration\Rag\Service\RagService;
use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectType;
use Bitrix\Bizproc\Starter;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Localization\Loc;

class Workflow implements \IBPWorkflowDocument
{
	private const DOCUMENT_TYPE = 'WORKFLOW';
	private const MODULE_ID = 'bizproc';

	public static function getEntityName(): string
	{
		return Loc::getMessage('BIZPROC_PUBLIC_ENTITY_DOCUMENT_WORKFLOW_ENTITY_NAME') ?? '';
	}

	/**
	 * @param int $templateId
	 * @param int $startedBy
	 * @return Starter\Starter|null
	 * @todo for tests only.
	 * @internal
	 */
	public static function getStarter(int $templateId, int $startedBy): ?Starter\Starter
	{
		if (!Starter\Starter::isEnabled())
		{
			return null;
		}

		return Starter\Starter::getByScenario(Starter\Enum\Scenario::onManual)
			->setContext(new Starter\Dto\ContextDto(self::MODULE_ID))
			->setUser($startedBy)
			->setTemplateIds([$templateId])
		;
	}

	/**
	 * @param int $templateId
	 * @param int $startedBy
	 * @return string|null Workflow ID.
	 * @todo for tests only.
	 * @internal
	 */
	public static function start(int $templateId, int $startedBy): ?string
	{
		$newId = \CBPRuntime::generateWorkflowId();
		$documentId = static::getComplexId($newId);

		$startParameters = [
			\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => \CBPDocumentEventType::Manual,
			\CBPDocument::PARAM_TAGRET_USER => "user_{$startedBy}",
			\CBPDocument::PARAM_PRE_GENERATED_WORKFLOW_ID => $newId,
		];

		$errors = [];

		return \CBPDocument::startWorkflow($templateId, $documentId, $startParameters, $errors);
	}

	public static function getComplexType(): array
	{
		return [self::MODULE_ID, static::class, self::DOCUMENT_TYPE];
	}

	public static function getComplexId(string $id): array
	{
		return [self::MODULE_ID, static::class, $id];
	}

	public static function getDocument($documentId): array
	{
		return [
			'ID' => $documentId,
		];
	}

	public static function getDocumentType(string $documentId): string
	{
		return self::DOCUMENT_TYPE;
	}

	public static function getDocumentFields($documentType): array
	{
		return [
			'ID' => [
				'Type' => 'string',
				'Name' => 'Workflow ID',
			],
		];
	}

	public static function createDocument($parentDocumentId, $arFields)
	{
		throw new NotImplementedException('Currently unavailable.');
	}

	public static function updateDocument($documentId, $arFields)
	{
		throw new NotImplementedException('Currently unavailable.');
	}

	public static function deleteDocument($documentId)
	{
		throw new NotImplementedException('Currently unavailable.');
	}

	public static function publishDocument($documentId): bool
	{
		return true;
	}

	public static function unpublishDocument($documentId): bool
	{
		return true;
	}

	public static function lockDocument($documentId, $workflowId): bool
	{
		return true;
	}

	public static function unlockDocument($documentId, $workflowId): bool
	{
		return true;
	}

	public static function isDocumentLocked($documentId, $workflowId): bool
	{
		return false;
	}

	public static function canUserOperateDocument($operation, $userId, $documentId, $arParameters = []): bool
	{
		// TODO: some logic here.
		return true;
	}

	public static function canUserOperateDocumentType($operation, $userId, $documentType, $arParameters = []): bool
	{
		// TODO: some logic here.
		return true;
	}

	public static function getDocumentAdminPage($documentId): string
	{
		// TODO: url here.
		return '/dev/null';
	}

	public static function getDocumentForHistory($documentId, $historyIndex): array
	{
		throw new NotImplementedException('Currently unavailable.');
	}

	public static function recoverDocumentFromHistory($documentId, $arDocument): bool
	{
		return true;
	}

	public static function getAllowableOperations($documentType): array
	{
		return [];
	}

	public static function getAllowableUserGroups($documentType): array
	{
		return [];
	}

	public static function getUsersFromUserGroup(mixed $group, mixed $documentId): array
	{
		if (!is_int($group) || (int)$group <= 0)
		{
			return [];
		}

		$group = (int)$group;
		$filter = ['ACTIVE' => 'Y', 'IS_REAL_USER' => true];
		if ($group != 2)
		{
			$filter['GROUPS_ID'] = $group;
		}

		$result = [];
		$dbUsersList = \CUser::GetList('ID', 'ASC', $filter, ['FIELDS' => ['ID']]);
		while ($arUser = $dbUsersList->Fetch())
		{
			$result[] = $arUser['ID'];
		}

		return $result;
	}

	public static function getDocumentFieldTypes($documentType): array
	{
		$types = \CBPHelper::GetDocumentFieldTypes();
		$isRagAvailable = ServiceLocator::getInstance()
			->get(RagService::class)
			->isAvailable()
		;
		if ($isRagAvailable)
		{
			$types[RagKnowledgeBaseType::getType()] = [
				'Name' => RagKnowledgeBaseType::getName(),
				'BaseType' => FieldType::STRING,
				'typeClass' => RagKnowledgeBaseType::class,
			];
		}

		if (ProjectType::isTypeAvailable())
		{
			$types[ProjectType::getType()] = [
				'Name' => ProjectType::getName(),
				'BaseType' => FieldType::INT,
				'typeClass' => ProjectType::class,
			];
		}

		return $types;
	}
}
