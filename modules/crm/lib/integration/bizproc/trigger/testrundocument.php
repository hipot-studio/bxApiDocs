<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Trigger;

use Bitrix\Bizproc\Public\Service\TestRun\TestRunService;

final class TestRunDocument
{
	private const DECLARATIONS = [
		\CCrmOwnerType::Deal => [
			'createActivity' => 'CreateCrmDealDocumentActivity',
			'createFields' => ['TITLE', 'CATEGORY_ID'],
			'documentSelector' => ['entityId' => 'deal'],
		],
		\CCrmOwnerType::Lead => [
			'createActivity' => 'CreateCrmLeadDocumentActivity',
			'createFields' => ['TITLE'],
			'documentSelector' => ['entityId' => 'lead'],
		],
		\CCrmOwnerType::Contact => [
			'createActivity' => 'CreateCrmContactDocumentActivity',
			'createFields' => ['NAME', 'LAST_NAME'],
			'documentSelector' => ['entityId' => 'contact'],
		],
		\CCrmOwnerType::Company => [
			'createActivity' => 'CreateCrmCompanyDocumentActivity',
			'createFields' => ['TITLE'],
			'documentSelector' => ['entityId' => 'company'],
		],
		\CCrmOwnerType::Quote => [
			'createFields' => ['TITLE'],
			'documentSelector' => ['entityId' => 'quote'],
		],
		\CCrmOwnerType::SmartInvoice => [
			'createFields' => ['TITLE'],
			'documentSelector' => ['entityId' => 'smart_invoice'],
		],
	];

	private const TYPES_REQUIRING_AUTOMATION_SUPPRESSION = [\CCrmOwnerType::Quote, \CCrmOwnerType::SmartInvoice];

	public static function getDeclarations(): array
	{
		$byDocumentType = [];
		foreach (self::getAvailableDeclarations() as $entityTypeId => $declaration)
		{
			$byDocumentType[implode('@', \CCrmBizProcHelper::ResolveDocumentType($entityTypeId))] = $declaration;
		}

		return ['byDocumentType' => $byDocumentType];
	}

	public static function getDeclaration(int $entityTypeId): ?array
	{
		return self::getAvailableDeclarations()[$entityTypeId] ?? null;
	}

	public static function getDeclarationForDocumentType(array $documentType): ?array
	{
		[$moduleId, , $entity] = array_values($documentType) + [null, null, null];
		if ($moduleId !== 'crm')
		{
			return null;
		}

		$entityTypeId = \CCrmOwnerType::ResolveID($entity);
		if (isset(self::DECLARATIONS[$entityTypeId]))
		{
			return self::DECLARATIONS[$entityTypeId];
		}

		if (!\CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId))
		{
			return null;
		}

		return [
			'createFields' => ['TITLE'],
			'documentSelector' => ['entityId' => 'dynamic', 'options' => ['entityTypeId' => $entityTypeId]],
		];
	}

	private static function getAvailableDeclarations(): array
	{
		if (method_exists(TestRunService::class, 'supportsNewDocumentAutomationSuppression'))
		{
			return self::DECLARATIONS;
		}

		return array_diff_key(self::DECLARATIONS, array_flip(self::TYPES_REQUIRING_AUTOMATION_SUPPRESSION));
	}
}
