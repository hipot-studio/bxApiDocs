<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Crm\Automation\Connectors\ItemRelations;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;

final class Resolver
{
	private const TARGET_FILTER_ID_PROPERTY = 'TargetFilterId';
	private const FILTER_SETTINGS_PROPERTY = 'FilterSettings';
	private const RESOLUTION_LIMIT = 150;
	private const RESOLUTION_ORDER = ['ID' => 'DESC'];

	public static function resolveDocuments(\CBPActivity $activity): array
	{
		$owner = self::findSettingsOwner($activity);
		if ($owner === null)
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
			$expectedTypeMatcher,
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
			$expectedTypeMatcher,
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
		callable $expectedTypeMatcher,
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

			$documentId = self::findDocumentIdInParentProperties($activity, $currentFilterId);
			if (
				is_array($documentId)
				&& $expectedTypeMatcher((int)$filter['targetEntityTypeId'])
			)
			{
				return $documentId;
			}

			if (($filter['sourceMode'] ?? null) !== 'filter')
			{
				break;
			}

			$currentFilterId = (string)($filter['sourceFilterId'] ?? '');
		}

		return null;
	}

	private static function findDocumentIdInParentProperties(\CBPActivity $activity, string $filterId): ?array
	{
		for ($parent = $activity->parent; $parent !== null; $parent = $parent->parent)
		{
			$documentId = $parent->{$filterId} ?? null;
			if (is_array($documentId))
			{
				return $documentId;
			}
		}

		return null;
	}

	private static function resolveDocumentIdByFilterChain(
		string $filterId,
		array $settingsById,
		array $resolved,
		callable $expectedTypeMatcher,
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

			if (
				isset($resolved[$currentFilterId])
				&& $expectedTypeMatcher((int)$filter['targetEntityTypeId'])
			)
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
		if ($targetEntityTypeId <= 0)
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
			'sourceMode' => $sourceMode,
			'sourceFilterId' => $sourceFilterId,
			'conditions' => is_array($filter['conditions'] ?? null) ? $filter['conditions'] : ['items' => []],
		];
	}

	private static function resolveFilter(\CBPActivity $activity, array $filter, array $resolved): ?array
	{
		$documentType = \CCrmBizProcHelper::ResolveDocumentType($filter['targetEntityTypeId']);
		if (!is_array($documentType))
		{
			return null;
		}

		$candidateIds = self::resolveCandidateIds($activity, $filter, $resolved);
		if (is_array($candidateIds) && empty($candidateIds))
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($filter['targetEntityTypeId']);
		if (!$factory)
		{
			return null;
		}

		$conditionGroup = new ConditionGroup($filter['conditions']);
		$conditionGroup->internalizeValues($documentType);
		$ormFilter = (new OrmFilterAdapter($documentType))->getOrmFilter($conditionGroup, $documentType);

		$queryFilter = [];
		if (is_array($candidateIds))
		{
			$queryFilter[] = ['@ID' => array_values(array_unique($candidateIds))];
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

	private static function resolveCandidateIds(\CBPActivity $activity, array $filter, array $resolved): ?array
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
				return $source['entityIds'] ?? [];
			}

			return self::getRelatedEntityIds($source['documentId'], $filter['targetEntityTypeId']);
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

			return [$rootEntityId];
		}

		return null;
	}

	private static function getRelatedEntityIds(array $sourceDocumentId, int $targetEntityTypeId): array
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

		$relations = new ItemRelations(new ItemIdentifier($sourceEntityTypeId, $sourceEntityId));
		$related = $relations->getParentElementIdentifiers($targetEntityTypeId);

		$result = [];
		foreach ($related as $identifier)
		{
			if ($identifier instanceof ItemIdentifier && $identifier->getEntityTypeId() === $targetEntityTypeId)
			{
				$result[] = $identifier->getEntityId();
			}
		}

		return $result;
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
