<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterSourceProvider;
use Bitrix\Crm\Automation\Connectors\ItemRelations;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RelationIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\ParentFieldManager;

final class Resolver
{
	private const TARGET_FILTER_ID_PROPERTY = 'TargetFilterId';
	private const FILTER_SETTINGS_PROPERTY = 'FilterSettings';
	private const RESOLUTION_LIMIT = 150;
	private const RESOLUTION_ORDER = ['ID' => 'DESC'];
	private const MULTI_VALUE_OPERATORS = ['in', '!in'];

	public static function resolveDocuments(
		\CBPActivity $activity,
		?DynamicTypeScopePolicy $scopePolicy = null,
	): array
	{
		$owner = self::findSettingsOwner($activity);
		if ($owner === null)
		{
			return [];
		}
		if (!self::ownerSettingsMatchScope($owner, $scopePolicy))
		{
			return [];
		}

		return self::resolveDocumentsByOwner($owner);
	}

	public static function resolveDocumentId(\CBPActivity $activity, ?int $expectedType = null): ?array
	{
		return self::resolveDocumentIdByTypeMatcher(
			$activity,
			static fn(int $targetEntityTypeId): bool => self::matchesExpectedType($targetEntityTypeId, $expectedType),
		);
	}

	public static function resolveDynamicDocumentId(\CBPActivity $activity): ?array
	{
		return self::resolveDocumentIdByTypeMatcher(
			$activity,
			static fn(int $targetEntityTypeId): bool => \CCrmOwnerType::isUseDynamicTypeBasedApproach($targetEntityTypeId),
		);
	}

	private static function resolveDocumentIdByTypeMatcher(\CBPActivity $activity, callable $expectedTypeMatcher): ?array
	{
		// A filter of an external source holds a document of another module: a CRM action never targets it.
		$filterMatcher = static fn(array $filter): bool =>
			!self::isExternalFilter($filter)
			&& $expectedTypeMatcher((int)$filter['targetEntityTypeId'])
		;

		$targetFilterId = self::resolveTargetFilterId($activity);
		if ($targetFilterId === null)
		{
			return null;
		}

		$owner = self::findSettingsOwner($activity);
		if ($owner === null)
		{
			return null;
		}
		if (!self::ownerSettingsMatchScope($owner))
		{
			return null;
		}

		$settings = self::readNormalizedSettings($owner);
		if (empty($settings))
		{
			return null;
		}

		$settingsById = self::indexSettingsById($settings);
		$resolvedDocumentId = self::resolveDocumentIdFromParentProperties(
			$activity,
			$targetFilterId,
			$settingsById,
			$filterMatcher,
		);
		if (is_array($resolvedDocumentId))
		{
			return $resolvedDocumentId;
		}

		$resolved = self::resolveDocumentsByOwner($owner);
		if (empty($resolved))
		{
			return null;
		}

		return self::resolveDocumentIdByFilterChain(
			$targetFilterId,
			$settingsById,
			$resolved,
			$filterMatcher,
		);
	}

	public static function validateSettings(mixed $settings): void
	{
		if ($settings === null || $settings === [])
		{
			return;
		}

		if (!is_array($settings))
		{
			return;
		}

		self::normalizeSettings($settings);
	}

	public static function validateSettingsScope(
		mixed $settings,
		DynamicTypeScope $scope,
		?DynamicTypeScopePolicy $scopePolicy = null,
	): void
	{
		if ($settings === null || $settings === [])
		{
			return;
		}
		if (!is_array($settings))
		{
			throw new \InvalidArgumentException('CRM node filter settings are invalid.');
		}

		$scopePolicy ??= DynamicTypeScopePolicy::getRequestInstance();
		foreach (self::normalizeSettings($settings) as $filter)
		{
			$entityTypeId = (int)$filter['targetEntityTypeId'];
			if (
				\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId)
				&& !$scopePolicy->contains($scope, $entityTypeId)
			)
			{
				throw new \InvalidArgumentException('Smart process type is outside the node scope.');
			}
		}
	}

	public static function isScopedActivity(\CBPActivity $activity): bool
	{
		return self::findScope($activity) !== null;
	}

	public static function hasTargetFilter(\CBPActivity $activity): bool
	{
		return self::resolveTargetFilterId($activity) !== null;
	}

	public static function isEntityTypeInScope(
		\CBPActivity $activity,
		int $entityTypeId,
		?DynamicTypeScopePolicy $scopePolicy = null,
	): bool
	{
		$scope = self::findScope($activity);
		if ($scope === null)
		{
			return true;
		}

		return ($scopePolicy ?? DynamicTypeScopePolicy::getRequestInstance())->contains($scope, $entityTypeId);
	}

	/**
	 * Finds the nearest activity (self or ancestor) that owns the node filter settings.
	 * Filter result documents are exposed on this activity, so it is also the activity whose
	 * runtime properties back the cross-filter `{=Node:filterId.FIELD}` condition expressions.
	 */
	private static function findSettingsOwner(\CBPActivity $activity): ?\CBPActivity
	{
		for ($current = $activity; $current !== null; $current = $current->parent)
		{
			if (self::hasSettings($current->{self::FILTER_SETTINGS_PROPERTY} ?? null))
			{
				return $current;
			}
		}

		return null;
	}

	private static function findScope(\CBPActivity $activity): ?DynamicTypeScope
	{
		for ($current = $activity; $current !== null; $current = $current->parent)
		{
			if (is_callable([$current, 'getNodeFilterDynamicTypeScope']))
			{
				$scope = $current::getNodeFilterDynamicTypeScope();
				if ($scope instanceof DynamicTypeScope)
				{
					return $scope;
				}
			}
		}

		return null;
	}

	private static function ownerSettingsMatchScope(
		\CBPActivity $owner,
		?DynamicTypeScopePolicy $scopePolicy = null,
	): bool
	{
		$scope = self::findScope($owner);
		if ($scope === null)
		{
			return true;
		}

		try
		{
			self::validateSettingsScope(
				$owner->{self::FILTER_SETTINGS_PROPERTY} ?? null,
				$scope,
				$scopePolicy,
			);

			return true;
		}
		catch (\InvalidArgumentException)
		{
			return false;
		}
	}

	private static function readNormalizedSettings(\CBPActivity $owner): array
	{
		$settings = $owner->{self::FILTER_SETTINGS_PROPERTY} ?? null;
		if (!self::hasSettings($settings))
		{
			return [];
		}

		try
		{
			return self::normalizeSettings($settings);
		}
		catch (\Throwable)
		{
			return [];
		}
	}

	private static function hasSettings(mixed $settings): bool
	{
		return is_array($settings) && !empty($settings);
	}

	private static function normalizeSettings(array $settings): array
	{
		$normalized = [];

		foreach ($settings as $index => $filter)
		{
			$normalizedFilter = self::normalizeFilter($filter, $index);
			if (!$normalizedFilter)
			{
				throw new \InvalidArgumentException('CRM node filter entry is invalid.');
			}

			if (isset($normalized[$normalizedFilter['id']]))
			{
				throw new \InvalidArgumentException('CRM node filter IDs must be unique.');
			}

			$normalized[$normalizedFilter['id']] = $normalizedFilter;
		}

		foreach ($normalized as $filter)
		{
			if (
				$filter['sourceMode'] === 'filter'
				&& !isset($normalized[$filter['sourceFilterId']])
			)
			{
				throw new \InvalidArgumentException('CRM node filter sourceFilterId is invalid.');
			}
		}

		return array_values($normalized);
	}

	private static function resolveDocumentsByOwner(\CBPActivity $owner): array
	{
		$settings = self::readNormalizedSettings($owner);
		if (empty($settings))
		{
			return [];
		}

		$resolved = [];

		foreach (array_keys($settings) as $index)
		{
			$currentSettings = self::readNormalizedSettings($owner);
			$filter = $currentSettings[$index] ?? $settings[$index];

			$result = self::resolveFilter($owner, $filter, $resolved);
			if ($result === null)
			{
				continue;
			}

			$resolved[$filter['id']] = $result;
			self::exposeResolvedDocument($owner, (string)$filter['id'], $result['documentId'] ?? null);
		}

		return $resolved;
	}

	/**
	 * Exposes a resolved filter document on the owner activity so that condition expressions of
	 * subsequent filters (`{=Node:filterId.FIELD}`) can read it at evaluation time. The matching
	 * runtime property is pre-initialized by BaseComplexActivity::initializeFilterResultProperties().
	 */
	private static function exposeResolvedDocument(\CBPActivity $owner, string $filterId, ?array $documentId): void
	{
		if ($documentId === null || $filterId === '')
		{
			return;
		}

		if ($owner->isPropertyExists($filterId))
		{
			$owner->{$filterId} = $documentId;
		}
	}

	private static function indexSettingsById(array $settings): array
	{
		$indexed = [];
		foreach ($settings as $filter)
		{
			if (isset($filter['id']))
			{
				$indexed[(string)$filter['id']] = $filter;
			}
		}

		return $indexed;
	}

	private static function resolveTargetFilterId(\CBPActivity $activity): ?string
	{
		$filterId = trim((string)($activity->{self::TARGET_FILTER_ID_PROPERTY} ?? ''));

		return $filterId !== '' ? $filterId : null;
	}

	private static function resolveDocumentIdFromParentProperties(
		\CBPActivity $activity,
		string $targetFilterId,
		array $settingsById,
		callable $filterMatcher,
	): ?array
	{
		$visited = [];
		$currentFilterId = $targetFilterId;

		while ($currentFilterId !== '' && !isset($visited[$currentFilterId]))
		{
			$visited[$currentFilterId] = true;
			$filter = $settingsById[$currentFilterId] ?? null;
			if (!is_array($filter))
			{
				return null;
			}

			$hasParentProperty = false;
			$parentDocumentId = null;
			for ($parent = $activity; $parent !== null; $parent = $parent->parent)
			{
				if (!$parent->isPropertyExists($currentFilterId))
				{
					continue;
				}

				$hasParentProperty = true;
				$parentDocumentId = $parent->{$currentFilterId} ?? null;
				break;
			}

			if ($hasParentProperty)
			{
				if (!is_array($parentDocumentId))
				{
					return [];
				}

				if ($filterMatcher($filter))
				{
					return $parentDocumentId;
				}
			}
			else if ($filterMatcher($filter))
			{
				break;
			}

			if (($filter['sourceMode'] ?? null) !== 'filter')
			{
				break;
			}

			$currentFilterId = (string)($filter['sourceFilterId'] ?? '');
		}

		return null;
	}

	private static function resolveDocumentIdByFilterChain(
		string $filterId,
		array $settingsById,
		array $resolved,
		callable $filterMatcher,
	): ?array
	{
		$visited = [];
		$currentFilterId = $filterId;

		while ($currentFilterId !== '' && !isset($visited[$currentFilterId]))
		{
			$visited[$currentFilterId] = true;
			$filter = $settingsById[$currentFilterId] ?? null;
			if (!is_array($filter))
			{
				return null;
			}

			if (!isset($resolved[$currentFilterId]))
			{
				return null;
			}

			if ($filterMatcher($filter))
			{
				return $resolved[$currentFilterId]['documentId'];
			}

			if (($filter['sourceMode'] ?? null) !== 'filter')
			{
				break;
			}

			$currentFilterId = (string)($filter['sourceFilterId'] ?? '');
		}

		return null;
	}

	private static function normalizeFilter(mixed $filter, int $index): ?array
	{
		if (!is_array($filter))
		{
			return null;
		}

		$targetEntityTypeId = (int)($filter['targetEntityTypeId'] ?? $filter['entityTypeId'] ?? 0);
		$targetEntityKey = $targetEntityTypeId > 0 ? '' : trim((string)($filter['targetEntityKey'] ?? ''));
		if ($targetEntityTypeId <= 0 && $targetEntityKey === '')
		{
			return null;
		}

		$sourceMode = (string)($filter['sourceMode'] ?? 'workflow');
		if (!in_array($sourceMode, ['workflow', 'filter'], true))
		{
			return null;
		}

		$sourceFilterId = trim((string)($filter['sourceFilterId'] ?? ''));
		if ($sourceMode === 'filter' && $sourceFilterId === '')
		{
			return null;
		}

		return [
			'id' => (string)($filter['id'] ?? ('filter_' . $index)),
			'targetEntityTypeId' => $targetEntityTypeId,
			'targetEntityKey' => $targetEntityKey,
			'sourceMode' => $sourceMode,
			'sourceFilterId' => $sourceFilterId,
			'conditions' => is_array($filter['conditions'] ?? null) ? $filter['conditions'] : ['items' => []],
		];
	}

	private static function resolveFilter(\CBPActivity $activity, array $filter, array $resolved): ?array
	{
		if (self::isExternalFilter($filter))
		{
			return self::resolveExternalFilter($activity, $filter);
		}

		$documentType = \CCrmBizProcHelper::ResolveDocumentType($filter['targetEntityTypeId']);
		if (!is_array($documentType))
		{
			return null;
		}

		$candidateConditions = self::resolveCandidateConditions($activity, $filter, $resolved);
		if (is_array($candidateConditions) && empty($candidateConditions))
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($filter['targetEntityTypeId']);
		if (!$factory)
		{
			return null;
		}

		$conditionGroup = new ConditionGroup(self::unserializeDealListValues($filter['conditions']));
		$conditionGroup->internalizeValues($documentType);
		foreach ($conditionGroup->getItems() as [$condition])
		{
			if ($condition->getOperator() === 'in' && $condition->getValue() === [])
			{
				$condition->setField('ID');
				$condition->setOperator('=');
				$condition->setValue(0);
			}
		}

		$ormFilter = (new OrmFilterAdapter($documentType))->getOrmFilter($conditionGroup, $documentType);

		$queryFilter = [];
		if (is_array($candidateConditions))
		{
			$queryFilter[] = count($candidateConditions) === 1
				? $candidateConditions[0]
				: ['LOGIC' => 'OR', ...$candidateConditions]
			;
		}
		if (!empty($filter['conditions']['items']))
		{
			$queryFilter[] = $ormFilter;
		}

		if (count($queryFilter) > 1)
		{
			$queryFilter = ['LOGIC' => 'AND', ...$queryFilter];
		}
		elseif (count($queryFilter) === 1)
		{
			$queryFilter = $queryFilter[0];
		}

		$items = $factory->getItems([
			'select' => ['ID'],
			'filter' => $queryFilter,
			'limit' => self::RESOLUTION_LIMIT,
			'order' => self::RESOLUTION_ORDER,
		]);

		if (empty($items))
		{
			return null;
		}

		$targetEntityTypeId = (int)$filter['targetEntityTypeId'];
		$entityIds = [];
		$documentIds = [];
		foreach ($items as $item)
		{
			$entityId = $item->getId();
			$entityIds[] = $entityId;
			$documentIds[] = \CCrmBizProcHelper::ResolveDocumentId($targetEntityTypeId, $entityId);
		}

		return [
			'documentId' => $documentIds[0],
			'documentIds' => $documentIds,
			'entityTypeId' => $targetEntityTypeId,
			'entityIds' => $entityIds,
		];
	}

	/**
	 * The designer stores the list of a multi-value operator as a JSON string in a single value slot.
	 * The deal field accepts any string and would otherwise be internalized as a list of that one string.
	 * Other fields keep the string: a user field internalizes a native list down to a single user.
	 */
	private static function unserializeDealListValues(array $conditions): array
	{
		$dealFieldId = ParentFieldManager::getParentFieldName(\CCrmOwnerType::Deal);

		foreach ($conditions['items'] ?? [] as $index => $item)
		{
			$condition = is_array($item) ? ($item[0] ?? null) : null;
			if (
				!is_array($condition)
				|| ($condition['field'] ?? null) !== $dealFieldId
				|| !in_array($condition['operator'] ?? null, self::MULTI_VALUE_OPERATORS, true)
				|| !is_string($condition['value'] ?? null)
			)
			{
				continue;
			}

			$decoded = json_decode($condition['value'], true);
			if (is_array($decoded) && array_is_list($decoded) && $decoded === array_filter($decoded, 'is_scalar'))
			{
				$conditions['items'][$index][0]['value'] = $decoded;
			}
		}

		return $conditions;
	}

	private static function isExternalFilter(array $filter): bool
	{
		return trim((string)($filter['targetEntityKey'] ?? '')) !== '';
	}

	private static function resolveExternalFilter(\CBPActivity $activity, array $filter): ?array
	{
		$provider = self::findExternalSourceProvider((string)$filter['targetEntityKey']);
		if ($provider === null)
		{
			return null;
		}

		$rootDocumentId = $activity->getRootActivity()->getDocumentId();
		if (!is_array($rootDocumentId) || count($rootDocumentId) < 3)
		{
			return null;
		}

		$documentIds = $provider->resolveDocumentIds(
			$filter['conditions'],
			$rootDocumentId,
			self::RESOLUTION_LIMIT,
		);
		if (empty($documentIds))
		{
			return null;
		}

		$documentIds = array_values($documentIds);

		return [
			'documentId' => $documentIds[0],
			'documentIds' => $documentIds,
			'entityTypeId' => 0,
			'entityIds' => [],
		];
	}

	private static function findExternalSourceProvider(string $sourceKey): ?NodeFilterSourceProvider
	{
		if (!method_exists(\Bitrix\Bizproc\Internal\Service\Container::class, 'getNodeFilterSourceRegistry'))
		{
			return null;
		}

		return \Bitrix\Bizproc\Internal\Service\Container::instance()
			->getNodeFilterSourceRegistry()
			->getProvider($sourceKey)
		;
	}

	/**
	 * Returns the alternatives that restrict the candidates of the filter as ORM conditions,
	 * or null when the candidates are not restricted at all. An empty list means no candidates.
	 *
	 * @return array[]|null
	 */
	private static function resolveCandidateConditions(\CBPActivity $activity, array $filter, array $resolved): ?array
	{
		if ($filter['sourceMode'] === 'filter')
		{
			$source = $resolved[$filter['sourceFilterId']] ?? null;
			if (!$source)
			{
				return [];
			}

			if ((int)$source['entityTypeId'] === (int)$filter['targetEntityTypeId'])
			{
				return self::makeIdCondition($source['entityIds'] ?? []);
			}

			return self::getRelatedConditions($source['documentId'], (int)$filter['targetEntityTypeId']);
		}

		$rootDocumentId = $activity->getRootActivity()->getDocumentId();
		if (!is_array($rootDocumentId) || count($rootDocumentId) < 3)
		{
			return null;
		}

		try
		{
			[$rootEntityTypeId, $rootEntityId] = \CCrmBizProcHelper::resolveEntityId($rootDocumentId);
		}
		catch (\Throwable)
		{
			return null;
		}

		if ($rootEntityTypeId <= 0 || $rootEntityId <= 0)
		{
			return null;
		}

		$targetEntityTypeId = (int)$filter['targetEntityTypeId'];

		if ($rootEntityTypeId === $targetEntityTypeId)
		{
			if (!empty($filter['conditions']['items']))
			{
				return null;
			}

			return self::makeIdCondition([$rootEntityId]);
		}

		return self::getRelatedConditions($rootDocumentId, $targetEntityTypeId);
	}

	/**
	 * @return array[]
	 */
	private static function getRelatedConditions(array $sourceDocumentId, int $targetEntityTypeId): array
	{
		try
		{
			[$sourceEntityTypeId, $sourceEntityId] = \CCrmBizProcHelper::resolveEntityId($sourceDocumentId);
		}
		catch (\Throwable)
		{
			return [];
		}

		if ($sourceEntityTypeId <= 0 || $sourceEntityId <= 0)
		{
			return [];
		}

		$source = new ItemIdentifier($sourceEntityTypeId, $sourceEntityId);

		$conditions = self::makeIdCondition(
			self::extractEntityIds(
				(new ItemRelations($source))->getParentElementIdentifiers($targetEntityTypeId),
				$targetEntityTypeId,
			)
		);

		$childrenFieldName = (new RelationFieldResolver())
			->findCustomParentField($sourceEntityTypeId, $targetEntityTypeId)
		;
		if ($childrenFieldName !== null)
		{
			$conditions[] = ['=' . $childrenFieldName => $sourceEntityId];

			return $conditions;
		}

		return array_merge(
			$conditions,
			self::makeIdCondition(
				self::extractEntityIds(self::getChildIdentifiers($source, $targetEntityTypeId), $targetEntityTypeId)
			)
		);
	}

	/**
	 * @return ItemIdentifier[]
	 */
	private static function getChildIdentifiers(ItemIdentifier $source, int $targetEntityTypeId): array
	{
		if ($source->getEntityTypeId() === $targetEntityTypeId)
		{
			return [];
		}

		$relation = Container::getInstance()->getRelationManager()->getRelation(
			new RelationIdentifier($source->getEntityTypeId(), $targetEntityTypeId)
		);

		return $relation?->getChildElements($source) ?? [];
	}

	/**
	 * @param ItemIdentifier[] $identifiers
	 * @return int[]
	 */
	private static function extractEntityIds(array $identifiers, int $targetEntityTypeId): array
	{
		$result = [];
		foreach ($identifiers as $identifier)
		{
			if ($identifier instanceof ItemIdentifier && $identifier->getEntityTypeId() === $targetEntityTypeId)
			{
				$result[] = $identifier->getEntityId();
			}
		}

		return $result;
	}

	/**
	 * @param int[] $entityIds
	 * @return array[]
	 */
	private static function makeIdCondition(array $entityIds): array
	{
		$ids = array_values(array_unique($entityIds));

		return $ids ? [['@ID' => $ids]] : [];
	}

	private static function matchesExpectedType(int $targetEntityTypeId, ?int $expectedType): bool
	{
		if ($expectedType === null)
		{
			return true;
		}

		return $expectedType === $targetEntityTypeId;
	}
}
