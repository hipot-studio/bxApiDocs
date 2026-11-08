<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Trigger;

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
	];

	public static function getDeclarations(): array
	{
		$byDocumentType = [];
		foreach (self::DECLARATIONS as $entityTypeId => $declaration)
		{
			$byDocumentType[implode('@', \CCrmBizProcHelper::ResolveDocumentType($entityTypeId))] = $declaration;
		}

		return ['byDocumentType' => $byDocumentType];
	}

	public static function getDeclaration(int $entityTypeId): ?array
	{
		return self::DECLARATIONS[$entityTypeId] ?? null;
	}
}
