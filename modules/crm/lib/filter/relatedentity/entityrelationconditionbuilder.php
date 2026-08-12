<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * Builds EXISTS / NOT EXISTS subquery for b_crm_entity_relation - universal polymorphic table
 * used for dynamic types and custom relations registered via RelationManager::bindTypes().
 *
 * The table stores relations with explicit direction via SRC_ENTITY_* and DST_ENTITY_* columns.
 * Whether source side maps to SRC or DST depends on how the relation was registered in
 * RelationManager - that information is available to the caller (Registry) and is passed in via
 * the $sourceIsParent flag.
 */
final class EntityRelationConditionBuilder implements ConditionBuilder
{
	private const TABLE_NAME = 'b_crm_entity_relation';

	/**
	 * @param bool $sourceIsParent True when source maps to SRC_ENTITY_* and target to DST_ENTITY_*.
	 *                             False when sides are swapped.
	 */
	public function __construct(
		private readonly bool $sourceIsParent = true
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
		[$sourceTypeCol, $sourceIdCol, $targetTypeCol] = $this->resolveColumns();
		$keyword = $hasRelation ? 'EXISTS' : 'NOT EXISTS';
		$table = self::TABLE_NAME;

		return "{$keyword} (SELECT 1 FROM {$table}"
			. " WHERE {$table}.{$sourceTypeCol} = {$sourceTypeId}"
			. " AND {$table}.{$sourceIdCol} = {$sourceTableAlias}.ID"
			. " AND {$table}.{$targetTypeCol} = {$targetTypeId})"
		;
	}

	public function buildOrmCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation
	): array
	{
		[$sourceTypeCol, $sourceIdCol, $targetTypeCol] = $this->resolveColumns();
		$runtimeFieldName = "REL_FILTER_{$sourceTypeId}_{$targetTypeId}";
		$table = self::TABLE_NAME;

		$expression = new ExpressionField(
			$runtimeFieldName,
			"(CASE WHEN EXISTS (SELECT 1 FROM {$table}"
				. " WHERE {$table}.{$sourceTypeCol} = {$sourceTypeId}"
				. " AND {$table}.{$sourceIdCol} = %s"
				. " AND {$table}.{$targetTypeCol} = {$targetTypeId}) THEN 1 ELSE 0 END)",
			['ID']
		);

		return [$runtimeFieldName, $expression, $hasRelation ? 1 : 0];
	}

	public function getCacheKey(): string
	{
		return 'relation|' . self::TABLE_NAME . '|' . ($this->sourceIsParent ? 'parent' : 'child');
	}

	public function hasAnyRecords(int $sourceTypeId, int $targetTypeId): bool
	{
		[$sourceTypeCol, , $targetTypeCol] = $this->resolveColumns();
		$table = self::TABLE_NAME;

		$row = Application::getConnection()
			->query("SELECT 1 FROM {$table}"
				. " WHERE {$table}.{$sourceTypeCol} = {$sourceTypeId}"
				. " AND {$table}.{$targetTypeCol} = {$targetTypeId} LIMIT 1"
			)->fetch()
		;

		return $row !== false;
	}

	/**
	 * @return array{0: string, 1: string, 2: string} [sourceTypeColumn, sourceIdColumn, targetTypeColumn]
	 */
	private function resolveColumns(): array
	{
		return $this->sourceIsParent
			? ['SRC_ENTITY_TYPE_ID', 'SRC_ENTITY_ID', 'DST_ENTITY_TYPE_ID']
			: ['DST_ENTITY_TYPE_ID', 'DST_ENTITY_ID', 'SRC_ENTITY_TYPE_ID']
		;
	}
}
