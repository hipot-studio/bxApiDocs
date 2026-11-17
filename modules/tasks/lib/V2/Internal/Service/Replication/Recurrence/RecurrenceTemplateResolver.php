<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;

class RecurrenceTemplateResolver
{
	public function __construct(
		private readonly TaskRightService $taskRightService,
		private readonly TemplateRightService $templateRightService,
		private readonly RecurrenceRuleService $recurrenceRuleService,
	)
	{
	}

	/**
	 * Task-based targets retain their input task ID, but expose a template ID only when the user can
	 * read both entities. Direct template IDs pass through for the calling tool's own access policy.
	 *
	 * @param int[]|null $templateIds
	 * @param int[]|null $taskIds
	 * @return array<int, array{taskId: ?int, templateId: ?int}>
	 */
	public function resolve(?array $templateIds, ?array $taskIds, int $userId): array
	{
		return $this->resolveTargets($templateIds, $taskIds, $userId, includeRulelessTemplates: false);
	}

	/**
	 * Resolves active readable templates for a read-only API even when they do not carry a valid
	 * recurrence rule. Mutation and AI callers keep using resolve(), whose target selection remains
	 * rule-aware.
	 *
	 * @param int[]|null $templateIds
	 * @param int[]|null $taskIds
	 * @return array<int, array{taskId: ?int, templateId: ?int}>
	 */
	public function resolveForReading(?array $templateIds, ?array $taskIds, int $userId): array
	{
		return $this->resolveTargets($templateIds, $taskIds, $userId, includeRulelessTemplates: true);
	}

	/**
	 * @param int[]|null $templateIds
	 * @param int[]|null $taskIds
	 * @return array<int, array{taskId: ?int, templateId: ?int}>
	 */
	private function resolveTargets(
		?array $templateIds,
		?array $taskIds,
		int $userId,
		bool $includeRulelessTemplates,
	): array
	{
		if ($templateIds !== null)
		{
			return array_map(
				static fn (int $templateId): array => [
					'taskId' => null,
					'templateId' => $templateId,
				],
				$templateIds,
			);
		}

		if ($taskIds === null || $taskIds === [])
		{
			return [];
		}

		$readableTaskIds = $this->loadReadableTaskIds($taskIds, $userId);
		$readableTaskIdsMap = array_fill_keys($readableTaskIds, true);
		$forkedTemplateIds = $includeRulelessTemplates
			? $this->loadForkedTemplateIdsForReading($readableTaskIds)
			: $this->loadForkedTemplateIds($readableTaskIds)
		;
		$originalTaskIds = [];
		foreach ($taskIds as $taskId)
		{
			if (($forkedTemplateIds[$taskId] ?? null) === 0)
			{
				$originalTaskIds[] = $taskId;
			}
		}

		$originalTemplateIds = $includeRulelessTemplates
			? $this->loadOriginalTemplateIdsForReading($originalTaskIds)
			: $this->loadOriginalTemplateIds($originalTaskIds)
		;

		$targets = [];
		foreach ($taskIds as $taskId)
		{
			if (!isset($readableTaskIdsMap[$taskId]))
			{
				$targets[] = [
					'taskId' => $taskId,
					'templateId' => null,
				];

				continue;
			}

			$forkedTemplateId = $forkedTemplateIds[$taskId] ?? null;
			$templateId = match (true)
			{
				($forkedTemplateId ?? 0) > 0 => $forkedTemplateId,
				$forkedTemplateId === 0 => $originalTemplateIds[$taskId] ?? null,
				default => null,
			};

			$targets[] = [
				'taskId' => $taskId,
				'templateId' => $templateId,
			];
		}

		$readableTemplateIds = array_fill_keys(
			$this->loadReadableTemplateIds($this->getTemplateIds($targets), $userId),
			true,
		);
		foreach ($targets as &$target)
		{
			if ($target['templateId'] !== null && !isset($readableTemplateIds[$target['templateId']]))
			{
				$target['templateId'] = null;
			}
		}
		unset($target);

		return $targets;
	}

	public function resolveOne(?int $templateId, ?int $taskId, int $userId): ?int
	{
		$targets = $this->resolve(
			$templateId === null ? null : [$templateId],
			$taskId === null ? null : [$taskId],
			$userId,
		);

		return $targets[0]['templateId'] ?? null;
	}

	/**
	 * @return array{taskId: ?int, templateId: ?int}|null
	 */
	public function resolveRuleTarget(?int $templateId, ?int $taskId, int $userId): ?array
	{
		if ($templateId !== null)
		{
			if (
				$this->loadReadableTemplateIds([$templateId], $userId) === []
				|| !isset($this->loadActiveTemplateIds([$templateId])[$templateId])
			)
			{
				return null;
			}

			return ['taskId' => null, 'templateId' => $templateId];
		}

		if ($taskId === null || $this->loadReadableTaskIds([$taskId], $userId) === [])
		{
			return null;
		}

		$forkedTemplateIds = $this->loadForkedTemplateIds([$taskId]);
		if (!array_key_exists($taskId, $forkedTemplateIds))
		{
			return null;
		}

		$resolvedTemplateId = $forkedTemplateIds[$taskId];
		if ($resolvedTemplateId === null)
		{
			return null;
		}

		if ($resolvedTemplateId <= 0)
		{
			$resolvedTemplateId = $this->loadOriginalTemplateIds([$taskId])[$taskId] ?? 0;
		}

		if ($resolvedTemplateId <= 0)
		{
			return ['taskId' => $taskId, 'templateId' => null];
		}

		if ($this->loadReadableTemplateIds([$resolvedTemplateId], $userId) === [])
		{
			return null;
		}

		return ['taskId' => null, 'templateId' => $resolvedTemplateId];
	}

	/**
	 * @param array<int, array{taskId: ?int, templateId: ?int}> $targets
	 * @return int[]
	 */
	public function getTemplateIds(array $targets): array
	{
		$templateIds = [];
		foreach ($targets as $target)
		{
			$templateId = $target['templateId'];
			if ($templateId !== null)
			{
				$templateIds[$templateId] = $templateId;
			}
		}

		return array_values($templateIds);
	}

	/**
	 * Existing tasks keyed by ID. Zero means the task is not an instance of a recurring template;
	 * null means its source template is no longer active or has no valid recurrence rule.
	 *
	 * @param int[] $taskIds
	 * @return array<int, ?int>
	 */
	protected function loadForkedTemplateIds(array $taskIds): array
	{
		if ($taskIds === [])
		{
			return [];
		}

		$rows = $this->loadTaskForkedTemplateRows($taskIds);

		$forkedTemplateIds = [];
		foreach ($rows as $row)
		{
			$forkedTemplateId = (int)($row['FORKED_BY_TEMPLATE_ID'] ?? 0);
			if ($forkedTemplateId > 0)
			{
				$forkedTemplateIds[$forkedTemplateId] = $forkedTemplateId;
			}
		}

		$sourceTemplateIds = array_values($forkedTemplateIds);
		$activeTemplateIds = $this->loadActiveTemplateIds($sourceTemplateIds);
		$idsWithoutRule = array_fill_keys(
			$this->recurrenceRuleService->filterWithoutRule($sourceTemplateIds),
			true,
		);
		$plainSourceTemplateIds = array_fill_keys(
			$this->loadPlainSourceTemplateIds(array_keys($idsWithoutRule)),
			true,
		);
		$result = [];
		foreach ($rows as $row)
		{
			$forkedTemplateId = (int)($row['FORKED_BY_TEMPLATE_ID'] ?? 0);
			$result[(int)$row['ID']] = match (true)
			{
				$forkedTemplateId <= 0 => 0,
				!isset($activeTemplateIds[$forkedTemplateId]) => null,
				isset($idsWithoutRule[$forkedTemplateId], $plainSourceTemplateIds[$forkedTemplateId]) => 0,
				isset($idsWithoutRule[$forkedTemplateId]) => null,
				default => $forkedTemplateId,
			};
		}

		return $result;
	}

	/**
	 * Existing tasks keyed by ID. Zero means there is no source template; null means the source
	 * template is no longer active. Unlike loadForkedTemplateIds(), a valid active association is
	 * retained even when its recurrence rule is absent or malformed.
	 *
	 * @param int[] $taskIds
	 * @return array<int, ?int>
	 */
	protected function loadForkedTemplateIdsForReading(array $taskIds): array
	{
		if ($taskIds === [])
		{
			return [];
		}

		$rows = $this->loadTaskForkedTemplateRows($taskIds);
		$sourceTemplateIds = [];
		foreach ($rows as $row)
		{
			$sourceTemplateId = (int)($row['FORKED_BY_TEMPLATE_ID'] ?? 0);
			if ($sourceTemplateId > 0)
			{
				$sourceTemplateIds[$sourceTemplateId] = $sourceTemplateId;
			}
		}

		$activeTemplateIds = $this->loadActiveTemplateIds(array_values($sourceTemplateIds));
		$result = [];
		foreach ($rows as $row)
		{
			$taskId = (int)$row['ID'];
			$sourceTemplateId = (int)($row['FORKED_BY_TEMPLATE_ID'] ?? 0);
			$result[$taskId] = match (true)
			{
				$sourceTemplateId <= 0 => 0,
				!isset($activeTemplateIds[$sourceTemplateId]) => null,
				default => $sourceTemplateId,
			};
		}

		return $result;
	}

	/**
	 * @param int[] $templateIds
	 * @return int[]
	 */
	protected function loadPlainSourceTemplateIds(array $templateIds): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		return array_map(
			'intval',
			TemplateTable::query()
				->setSelect(['ID'])
				->whereIn('ID', $templateIds)
				->where('ZOMBIE', 'N')
				->where('REPLICATE', 'N')
				->whereNull('REPLICATE_TYPE')
				->where('TPARAM_REPLICATION_COUNT', 0)
				->fetchCollection()
				->getIdList(),
		);
	}

	/**
	 * @param int[] $taskIds
	 * @return array<int, array{ID: int|string, FORKED_BY_TEMPLATE_ID: int|string|null}>
	 */
	protected function loadTaskForkedTemplateRows(array $taskIds): array
	{
		return TaskTable::query()
			->setSelect(['ID', 'FORKED_BY_TEMPLATE_ID'])
			->whereIn('ID', $taskIds)
			->fetchAll()
		;
	}

	/**
	 * @param int[] $templateIds
	 * @return array<int, int>
	 */
	protected function loadActiveTemplateIds(array $templateIds): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$rows = TemplateTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $templateIds)
			->where('ZOMBIE', 'N')
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$templateId = (int)$row['ID'];
			$result[$templateId] = $templateId;
		}

		return $result;
	}

	/**
	 * @param int[] $taskIds
	 * @return int[]
	 */
	protected function loadReadableTaskIds(array $taskIds, int $userId): array
	{
		if ($taskIds === [])
		{
			return [];
		}

		$readAction = ActionDictionary::TASK_ACTIONS['read'];
		$rightsByTaskId = $this->taskRightService->getTaskRightsBatch(
			$userId,
			$taskIds,
			['read' => $readAction],
		);

		$readableTaskIds = [];
		foreach ($taskIds as $taskId)
		{
			if (($rightsByTaskId[$taskId]['read'] ?? false) === true)
			{
				$readableTaskIds[] = $taskId;
			}
		}

		return $readableTaskIds;
	}

	/**
	 * @param int[] $templateIds
	 * @return int[]
	 */
	protected function loadReadableTemplateIds(array $templateIds, int $userId): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$readAction = ActionDictionary::TEMPLATE_ACTIONS['read'];
		$rightsByTemplateId = $this->templateRightService->getTemplateRightsBatch(
			$userId,
			$templateIds,
			['read' => $readAction],
		);

		$readableTemplateIds = [];
		foreach ($templateIds as $templateId)
		{
			if (($rightsByTemplateId[$templateId]['read'] ?? false) === true)
			{
				$readableTemplateIds[] = $templateId;
			}
		}

		return $readableTemplateIds;
	}

	/**
	 * The first active template linked to each original task, matching TemplateRepository::getByTaskId().
	 *
	 * @param int[] $taskIds
	 * @return array<int, int>
	 */
	protected function loadOriginalTemplateIds(array $taskIds): array
	{
		$taskIds = array_values(
			array_unique(
				array_filter(
					array_map('intval', $taskIds),
					static fn(int $taskId): bool => $taskId > 0,
				),
			),
		);
		if ($taskIds === [])
		{
			return [];
		}

		$rows = $this->loadOriginalTemplateRows($taskIds);

		$idsWithoutRule = array_fill_keys(
			$this->recurrenceRuleService->filterWithoutRule(
				array_map('intval', array_column($rows, 'TEMPLATE_ID')),
			),
			true,
		);
		$result = [];
		foreach ($rows as $row)
		{
			$templateId = (int)$row['TEMPLATE_ID'];
			if (isset($idsWithoutRule[$templateId]))
			{
				continue;
			}

			$taskId = (int)$row['TASK_ID'];
			$result[$taskId] ??= $templateId;
		}

		return $result;
	}

	/**
	 * Read resolution keeps raw recurrence candidates so conversion failures can be reported per item.
	 *
	 * @param int[] $taskIds
	 * @return array<int, int>
	 */
	protected function loadOriginalTemplateIdsForReading(array $taskIds): array
	{
		$taskIds = array_values(
			array_unique(
				array_filter(
					array_map('intval', $taskIds),
					static fn(int $taskId): bool => $taskId > 0,
				),
			),
		);
		if ($taskIds === [])
		{
			return [];
		}

		$templateIds = [];
		foreach ($this->loadOriginalTemplateRows($taskIds) as $row)
		{
			$taskId = (int)$row['TASK_ID'];
			$templateIds[$taskId] ??= (int)$row['TEMPLATE_ID'];
		}
		$unresolvedTaskIds = [];
		foreach ($taskIds as $taskId)
		{
			if (!isset($templateIds[$taskId]))
			{
				$unresolvedTaskIds[] = $taskId;
			}
		}

		if ($unresolvedTaskIds === [])
		{
			return $templateIds;
		}

		return $templateIds + $this->loadFirstActiveOriginalTemplateIds($unresolvedTaskIds);
	}

	/**
	 * @param int[] $taskIds
	 * @return array<int, int>
	 */
	protected function loadFirstActiveOriginalTemplateIds(array $taskIds): array
	{
		if ($taskIds === [])
		{
			return [];
		}

		$rows = TemplateTable::query()
			->setSelect([
				'TASK_ID',
				new ExpressionField('TEMPLATE_ID', 'MIN(%s)', 'ID'),
			])
			->whereIn('TASK_ID', $taskIds)
			->where('ZOMBIE', 'N')
			->setGroup(['TASK_ID'])
			->setLimit(count($taskIds))
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['TASK_ID']] = (int)$row['TEMPLATE_ID'];
		}

		return $result;
	}

	/**
	 * @param int[] $taskIds
	 * @return array<int, array{TEMPLATE_ID: int|string, TASK_ID: int|string}>
	 */
	protected function loadOriginalTemplateRows(array $taskIds): array
	{
		return $this->buildOriginalTemplateRowsQuery($taskIds)->fetchAll();
	}

	/**
	 * @param int[] $taskIds
	 */
	protected function buildOriginalTemplateRowsQuery(array $taskIds): Query
	{
		return TemplateTable::query()
			->setSelect([
				'TASK_ID',
				new ExpressionField('TEMPLATE_ID', 'MIN(%s)', 'ID'),
			])
			->whereIn('TASK_ID', $taskIds)
			->where('ZOMBIE', 'N')
			->whereNotNull('REPLICATE_PARAMS')
			->whereNot('REPLICATE_PARAMS', '')
			->setGroup(['TASK_ID'])
			->setLimit(count($taskIds))
		;
	}
}
