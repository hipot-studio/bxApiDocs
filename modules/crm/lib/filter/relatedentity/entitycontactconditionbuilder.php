<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;
use CCrmOwnerType;

/**
 * Builds EXISTS / NOT EXISTS subquery for the polymorphic b_crm_entity_contact table.
 * It binds Contact to Quote, SmartInvoice and dynamic types via the ENTITY_TYPE_ID discriminator.
 *
 * Either sourceTypeId or targetTypeId must be CCrmOwnerType::Contact - the other side is used
 * as the discriminator value for ENTITY_TYPE_ID.
 */
final class EntityContactConditionBuilder implements ConditionBuilder
{
	private const TABLE_NAME = 'b_crm_entity_contact';

	public function buildLegacyCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation,
		string $sourceTableAlias
	): string
	{
		[$sourceField, $discriminator] = $this->resolveSidesOrFail($sourceTypeId, $targetTypeId);
		$keyword = $hasRelation ? 'EXISTS' : 'NOT EXISTS';
		$table = self::TABLE_NAME;

		return "{$keyword} (SELECT 1 FROM {$table}"
			. " WHERE {$table}.{$sourceField} = {$sourceTableAlias}.ID"
			. " AND {$table}.ENTITY_TYPE_ID = {$discriminator})"
		;
	}

	public function buildOrmCondition(
		int $sourceTypeId,
		int $targetTypeId,
		bool $hasRelation
	): array
	{
		[$sourceField, $discriminator] = $this->resolveSidesOrFail($sourceTypeId, $targetTypeId);
		$runtimeFieldName = "REL_FILTER_{$sourceTypeId}_{$targetTypeId}";
		$table = self::TABLE_NAME;

		$expression = new ExpressionField(
			$runtimeFieldName,
			"(CASE WHEN EXISTS (SELECT 1 FROM {$table}"
				. " WHERE {$table}.{$sourceField} = %s"
				. " AND {$table}.ENTITY_TYPE_ID = {$discriminator}) THEN 1 ELSE 0 END)",
			['ID']
		);

		return [$runtimeFieldName, $expression, $hasRelation ? 1 : 0];
	}

	public function getCacheKey(): string
	{
		// Single-table polymorphic storage; pair-level discriminator is appended by the caller.
		return 'contact|' . self::TABLE_NAME;
	}

	public function hasAnyRecords(int $sourceTypeId, int $targetTypeId): bool
	{
		$discriminator = $this->resolveDiscriminatorOrFail($sourceTypeId, $targetTypeId);
		$table = self::TABLE_NAME;

		$row = Application::getConnection()
			->query("SELECT 1 FROM {$table} WHERE {$table}.ENTITY_TYPE_ID = {$discriminator} LIMIT 1")
			->fetch()
		;

		return $row !== false;
	}

	/**
	 * Returns the ENTITY_TYPE_ID discriminator value for the non-Contact side of the pair.
	 * Used by hasAnyRecords(), which only probes for at least one row of that type.
	 */
	private function resolveDiscriminatorOrFail(int $sourceTypeId, int $targetTypeId): int
	{
		return $this->resolveSidesOrFail($sourceTypeId, $targetTypeId)[1];
	}

	/**
	 * @return array{0: string, 1: int} [sourceFieldName, discriminatorValue]
	 */
	private function resolveSidesOrFail(int $sourceTypeId, int $targetTypeId): array
	{
		if ($sourceTypeId === CCrmOwnerType::Contact)
		{
			return ['CONTACT_ID', $targetTypeId];
		}

		if ($targetTypeId === CCrmOwnerType::Contact)
		{
			return ['ENTITY_ID', $sourceTypeId];
		}

		$contactTypeId = CCrmOwnerType::Contact;

		throw new \InvalidArgumentException(
			"Either sourceTypeId or targetTypeId must be Contact ({$contactTypeId}),"
				. " got source={$sourceTypeId}, target={$targetTypeId}"
		);
	}
}
