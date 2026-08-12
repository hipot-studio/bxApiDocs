<?php

namespace Bitrix\Crm\Filter\RelatedEntity;

use Bitrix\Crm\Service\Container;
use Closure;

/**
 * Reads the RELATED_ENTITIES key from the user filter, resolves a ConditionBuilder via Registry
 * and injects the resulting SQL fragment / ORM expression into the underlying request.
 *
 * Two entry points cover both code paths used across CRM lists:
 *  - applyToLegacyFilter() - for $arFilter['__CONDITIONS'] consumed by CCrmEntityListBuilder
 *    (Contact, Company, Lead, Deal, Quote - list and kanban).
 *  - applyToOrmParameters() - for ORM 'runtime'/'filter' consumed by Service\Factory::getItems()
 *    (SmartInvoice, dynamic - list, kanban, deadlines).
 *
 * Both methods mutate their input arrays in place and remove the RELATED_ENTITIES key so that
 * the base filter pipeline does not try to resolve it against the main entity table.
 *
 * Server-side guard: even when the request comes from a hand-crafted HTTP call (REST, AJAX),
 * the filter is silently ignored for target types the current user cannot read. The UI never
 * advertises such targets, but the runtime check here makes sure the SQL EXISTS subquery is
 * never injected behind the UI's back.
 */
final class FilterApplier
{
	public const FILTER_KEY = 'RELATED_ENTITIES';

	public const VALUE_PREFIX_HAS = 'has_';
	public const VALUE_PREFIX_NO = 'no_';

	/**
	 * @param Closure(int $targetTypeId): bool|null $canReadTargetType Optional override for the
	 *     target-type permission check, used by unit tests to inject a stub without booting the
	 *     full UserPermissions service. When null, falls back to the production resolver based
	 *     on Container::getUserPermissions()->entityType()->canReadItems().
	 */
	public function __construct(
		private readonly ConditionBuilderResolver $registry,
		private readonly ?Closure $canReadTargetType = null
	)
	{
	}

	public function applyToLegacyFilter(array &$arFilter, int $sourceTypeId, string $sourceTableAlias): void
	{
		$parsed = $this->extractValue($arFilter);
		if ($parsed === null)
		{
			return;
		}

		[$hasRelation, $targetTypeId] = $parsed;
		if (!$this->isTargetReadable($targetTypeId))
		{
			return;
		}

		$builder = $this->registry->getConditionBuilder($sourceTypeId, $targetTypeId);
		if ($builder === null)
		{
			return;
		}

		$sql = $builder->buildLegacyCondition($sourceTypeId, $targetTypeId, $hasRelation, $sourceTableAlias);
		$arFilter['__CONDITIONS'] ??= [];
		$arFilter['__CONDITIONS'][] = ['SQL' => $sql];
	}

	public function applyToOrmParameters(array &$parameters, int $sourceTypeId): void
	{
		if (!isset($parameters['filter']) || !is_array($parameters['filter']))
		{
			return;
		}

		$parsed = $this->extractValue($parameters['filter']);
		if ($parsed === null)
		{
			return;
		}

		[$hasRelation, $targetTypeId] = $parsed;
		if (!$this->isTargetReadable($targetTypeId))
		{
			return;
		}

		$builder = $this->registry->getConditionBuilder($sourceTypeId, $targetTypeId);
		if ($builder === null)
		{
			return;
		}

		[$runtimeFieldName, $expression, $filterValue] =
			$builder->buildOrmCondition($sourceTypeId, $targetTypeId, $hasRelation)
		;

		$parameters['runtime'] ??= [];
		$parameters['runtime'][$runtimeFieldName] = $expression;
		$parameters['filter']['=' . $runtimeFieldName] = $filterValue;
	}

	private function isTargetReadable(int $targetTypeId): bool
	{
		if ($this->canReadTargetType !== null)
		{
			return (bool)($this->canReadTargetType)($targetTypeId);
		}

		return Container::getInstance()
			->getUserPermissions()
			->entityType()
			->canReadItems($targetTypeId)
		;
	}

	/**
	 * Extracts and removes RELATED_ENTITIES value from the given filter-like array.
	 *
	 * Accepts both the raw key (legacy filter path, request as-is) and the equality-prefixed
	 * key (Service\Factory ORM path through ListFilter::prepareListFilter which prepends '='
	 * for fields of type 'list').
	 *
	 * @return array{0: bool, 1: int}|null [hasRelation, targetTypeId] or null when not applicable.
	 */
	private function extractValue(array &$filter): ?array
	{
		$prefixedKey = '=' . self::FILTER_KEY;
		$value = $filter[self::FILTER_KEY] ?? $filter[$prefixedKey] ?? null;
		unset($filter[self::FILTER_KEY], $filter[$prefixedKey]);

		if (!is_string($value) || $value === '')
		{
			return null;
		}

		if (str_starts_with($value, self::VALUE_PREFIX_HAS))
		{
			$targetTypeId = (int)substr($value, strlen(self::VALUE_PREFIX_HAS));
			return $targetTypeId > 0 ? [true, $targetTypeId] : null;
		}

		if (str_starts_with($value, self::VALUE_PREFIX_NO))
		{
			$targetTypeId = (int)substr($value, strlen(self::VALUE_PREFIX_NO));
			return $targetTypeId > 0 ? [false, $targetTypeId] : null;
		}

		return null;
	}
}
