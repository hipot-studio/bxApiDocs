<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\BizProc\NodeFilter;

use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;
use Bitrix\Tasks\Internals\TaskTable;

final class Resolver
{
	private const TARGET_FILTER_ID_PROPERTY = 'TargetFilterId';
	private const FILTER_SETTINGS_PROPERTY = 'FilterSettings';
	private const TASK_ENTITY_TYPE_ID = 1;
	private const RESOLUTION_LIMIT = 150;
	private const RESOLUTION_ORDER = ['ID' => 'DESC'];

	public static function resolveDocuments(\CBPActivity $activity): array
	{
		$settings = self::getFilterSettings($activity);
		if (empty($settings))
		{
			return [];
		}

		return self::resolveDocumentsBySettings($activity, $settings);
	}

	public static function resolveDocumentId(\CBPActivity $activity): ?array
	{
		$targetFilterId = self::resolveTargetFilterId($activity);
		if ($targetFilterId === null)
		{
			return null;
		}

		$settings = self::getFilterSettings($activity);
		if (empty($settings))
		{
			return null;
		}

		$settingsById = self::indexSettingsById($settings);

		$documentIdFromParent = self::findDocumentIdInParentProperties($activity, $targetFilterId);
		if (is_array($documentIdFromParent))
		{
			return $documentIdFromParent;
		}

		$resolved = self::resolveDocumentsBySettings($activity, $settings);
		if (empty($resolved))
		{
			return null;
		}

		return self::resolveDocumentIdByFilterChain($targetFilterId, $settingsById, $resolved);
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

	private static function getFilterSettings(\CBPActivity $activity): array
	{
		for ($current = $activity; $current !== null; $current = $current->parent)
		{
			$settings = $current->{self::FILTER_SETTINGS_PROPERTY} ?? null;
			if (!self::hasSettings($settings))
			{
				continue;
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

		return [];
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
				throw new \InvalidArgumentException('Tasks node filter entry is invalid.');
			}

			if (isset($normalized[$normalizedFilter['id']]))
			{
				throw new \InvalidArgumentException('Tasks node filter IDs must be unique.');
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
				throw new \InvalidArgumentException('Tasks node filter sourceFilterId is invalid.');
			}
		}

		return array_values($normalized);
	}

	private static function resolveDocumentsBySettings(\CBPActivity $activity, array $settings): array
	{
		$resolved = [];

		foreach ($settings as $filter)
		{
			$result = self::resolveFilter($activity, $filter, $resolved);
			if ($result === null)
			{
				continue;
			}

			$resolved[$filter['id']] = $result;
		}

		return $resolved;
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

			if (isset($resolved[$currentFilterId]))
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
			'targetEntityTypeId' => self::TASK_ENTITY_TYPE_ID,
			'sourceMode' => $sourceMode,
			'sourceFilterId' => $sourceFilterId,
			'conditions' => is_array($filter['conditions'] ?? null) ? $filter['conditions'] : ['items' => []],
		];
	}

	private static function resolveFilter(\CBPActivity $activity, array $filter, array $resolved): ?array
	{
		$documentType = ['tasks', Task::class, 'TASK'];

		$candidateIds = self::resolveCandidateIds($activity, $filter, $resolved);
		if (is_array($candidateIds) && empty($candidateIds))
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

		$result = TaskTable::getList([
			'select' => ['ID'],
			'filter' => $queryFilter,
			'limit' => self::RESOLUTION_LIMIT,
			'order' => self::RESOLUTION_ORDER,
		]);

		$entityIds = [];
		$documentIds = [];
		while ($row = $result->fetch())
		{
			$taskId = (int)($row['ID'] ?? 0);
			if ($taskId <= 0)
			{
				continue;
			}

			$entityIds[] = $taskId;
			$documentIds[] = Task::resolveDocumentId($taskId);
		}

		if (empty($entityIds))
		{
			return null;
		}

		return [
			'documentId' => $documentIds[0],
			'documentIds' => $documentIds,
			'entityTypeId' => self::TASK_ENTITY_TYPE_ID,
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

			return $source['entityIds'] ?? [];
		}

		$rootDocumentId = $activity->getRootActivity()->getDocumentId();
		$rootTaskId = self::extractTaskId($rootDocumentId);
		if ($rootTaskId === null)
		{
			return null;
		}

		// The first same-entity filter in a complex node may explicitly query against all tasks
		// (lookup semantics) rather than filter the workflow's own document.
		if (!empty($filter['conditions']['items']))
		{
			return null;
		}

		return [$rootTaskId];
	}

	private static function extractTaskId(mixed $documentId): ?int
	{
		if (!is_array($documentId) || ($documentId[0] ?? null) !== 'tasks')
		{
			return null;
		}

		$id = (int)($documentId[2] ?? 0);

		return $id > 0 ? $id : null;
	}
}
