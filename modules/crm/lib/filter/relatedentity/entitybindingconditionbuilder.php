<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * Builds EXISTS / NOT EXISTS subquery for plain binding tables without a polymorphic discriminator,
 * such as b_crm_deal_contact, b_crm_lead_contact, b_crm_contact_company.
 *
 * Invariant: one builder instance is bound to exactly one table, and that table stores exactly
 * one pair of entity types (no discriminator column). The pair is implicitly defined by the
 * table choice and the entityFieldByTypeId map; the $sourceTypeId / $targetTypeId arguments
 * passed into the build* / hasAnyRecords methods must match that fixed pair.
 *
 * Each builder instance is configured for a single binding table and a map of entity type IDs to
 * column names (which side of the binding holds the reference to which CRM entity type).
 */
final class EntityBindingConditionBuilder implements ConditionBuilder
{
	/**
	 * @param string $tableName Physical table name, e.g. 'b_crm_deal_contact'.
	 * @param array<int, string> $entityFieldByTypeId Map: entityTypeId => column name in the binding table.
	 */
	public function __construct(
		private readonly string $tableName,
		private readonly array $entityFieldByTypeId
	)
	{
	}

	public function buildLegacyCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation,
		string $sourceTableAlias
	): string
	{
		$sourceField = $this->getFieldOrFail($sourceTypeId);
		$keyword = $hasRelation ? 'EXISTS' : 'NOT EXISTS';
		$table = $this->tableName;

		return "{$keyword} (SELECT 1 FROM {$table} WHERE {$table}.{$sourceField} = {$sourceTableAlias}.ID)";
	}

	public function buildOrmCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation
	): array
	{
		$sourceField = $this->getFieldOrFail($sourceTypeId);
		$runtimeFieldName = "REL_FILTER_{$sourceTypeId}_{$targetTypeId}";
		$table = $this->tableName;

		$expression = new ExpressionField(
			$runtimeFieldName,
			"(CASE WHEN EXISTS (SELECT 1 FROM {$table} WHERE {$table}.{$sourceField} = %s) THEN 1 ELSE 0 END)",
			['ID']
		);

		return [$runtimeFieldName, $expression, $hasRelation ? 1 : 0];
	}

	/**
	 * Per the class invariant the bound table holds exactly the pair this builder serves, so
	 * "any record in the table" is equivalent to "any record for the pair". The arguments are
	 * kept to satisfy the ConditionBuilder contract and validated against the configured pair.
	 */
	public function getCacheKey(): string
	{
		// Pair-level values come from the caller, here we only fingerprint the storage layout.
		$fields = $this->entityFieldByTypeId;
		ksort($fields);
		$layout = [];
		foreach ($fields as $typeId => $column)
		{
			$layout[] = $typeId . '=' . $column;
		}

		return 'binding|' . $this->tableName . '|' . implode(',', $layout);
	}

	public function hasAnyRecords(int $sourceTypeId, int $targetTypeId): bool
	{
		$this->getFieldOrFail($sourceTypeId);
		$this->getFieldOrFail($targetTypeId);
		$table = $this->tableName;

		$row = Application::getConnection()
			->query("SELECT 1 FROM {$table} LIMIT 1")
			->fetch()
		;

		return $row !== false;
	}

	private function getFieldOrFail(int $entityTypeId): string
	{
		if (!isset($this->entityFieldByTypeId[$entityTypeId]))
		{
			throw new \InvalidArgumentException(
				"Entity type {$entityTypeId} is not configured for binding table {$this->tableName}"
			);
		}

		return $this->entityFieldByTypeId[$entityTypeId];
	}
}
