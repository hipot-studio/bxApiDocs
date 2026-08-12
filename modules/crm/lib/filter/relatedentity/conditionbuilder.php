<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Main\ORM\Fields\ExpressionField;

interface ConditionBuilder
{
	/**
	 * Builds SQL fragment for $arFilter['__CONDITIONS'] used by CCrmEntityListBuilder.
	 */
	public function buildLegacyCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation,
		string $sourceTableAlias
	): string;

	/**
	 * Builds runtime ORM field and filter value for factory->getItems().
	 *
	 * @return array{0: string, 1: ExpressionField, 2: int} [runtimeFieldName, expressionField, filterValue]
	 */
	public function buildOrmCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation
	): array;

	/**
	 * Returns true when the underlying binding storage holds at least one record for the pair.
	 * Used by Registry to drop options from the filter UI when the corresponding "has"/"no" pair
	 * would always return either an empty list or all entities.
	 */
	public function hasAnyRecords(int $sourceTypeId, int $targetTypeId): bool;

	/**
	 * Stable identifier of the builder's storage configuration (table, columns, direction).
	 * Used as part of the cache key in {@see Registry::hasAnyRecordsCached()} so that several
	 * builder instances configured the same way share the same cache slot. Must NOT include
	 * pair-level values (sourceTypeId / targetTypeId) - the caller appends those.
	 */
	public function getCacheKey(): string;
}
