<?php

namespace Bitrix\Crm\Kanban\Entity;

use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Settings\CounterSettings;
use Bitrix\Main\Result;

class DynamicActivities extends Dynamic
{
	use ActivityTrait;

	public function shouldShowFinalStagesWithDefaultFilter(): bool
	{
		return false;
	}

	public function shouldShowFinalStagesWithEmptyFilter(): bool
	{
		return true;
	}

	/**
	 * Overrides ActivityTrait::getItems() to avoid using __CONDITIONS SQL subqueries,
	 * which are incompatible with the D7 ORM factory used by Dynamic entity types.
	 *
	 * Entity IDs matching the requested activity stage are fetched from EntityCounter ORM
	 * queries and applied as an @ID filter on the factory query. ACTIVITY_COUNTER and the
	 * counter responsible field (ASSIGNED_BY_ID for "by element" counters, ACTIVITY_RESPONSIBLE_IDS
	 * for "by activity") are consumed by getEntityIdsForStage() and stripped before calling the
	 * factory: the responsible field is left untransformed by Kanban::getFilter() (it may hold
	 * special tokens like "other-users") and its restriction is already encoded in the entity IDs.
	 */
	public function getItems(array $parameters): \CDBResult
	{
		$filter = $parameters['filter'] ?? [];
		$requestedStageId = $filter[EntityActivities::ACTIVITY_STAGE_ID] ?? '';
		unset($filter[EntityActivities::ACTIVITY_STAGE_ID]);

		$entityActivities = $this->getEntityActivities();

		$counterResponsibleField = CounterSettings::getInstance()->useActivityResponsible()
			? 'ACTIVITY_RESPONSIBLE_IDS'
			: 'ASSIGNED_BY_ID';

		$factoryFilter = $filter;
		$stageSemanticFilter = $factoryFilter[EntityActivities::STAGE_SEMANTIC_FILTER] ?? null;
		$this->unsetFilterKey($factoryFilter, EntityActivities::SHOW_ALL_STAGE_SEMANTICS);
		$this->unsetFilterKey($factoryFilter, EntityActivities::STAGE_SEMANTIC_FILTER);
		$this->applyStageSemanticFilter($factoryFilter, $stageSemanticFilter);
		$this->unsetFilterKey($factoryFilter, 'ACTIVITY_COUNTER');
		$this->unsetFilterKey($factoryFilter, 'ACTIVITY_RESPONSIBLE_IDS');
		$this->unsetFilterKey($factoryFilter, $counterResponsibleField);

		if ($this->factory->isRecurringEnabled())
		{
			$factoryFilter['!=IS_RECURRING'] = 'Y';
		}

		if ($requestedStageId === '')
		{
			$parameters['filter'] = $factoryFilter;
			$rawResult = parent::getItems($parameters);

			return $entityActivities->prepareItemsResult('', $rawResult, $filter);
		}

		$entityIds = $entityActivities->getEntityIdsForStage($requestedStageId, $filter);

		if ($entityIds === null || empty($entityIds))
		{
			$empty = new \CDBResult();
			$empty->InitFromArray([]);
			return $entityActivities->prepareItemsResult($requestedStageId, $empty, $filter);
		}

		$factoryFilter['@ID'] = $entityIds;
		$parameters['filter'] = $factoryFilter;
		$rawResult = parent::getItems($parameters);

		return $entityActivities->prepareItemsResult($requestedStageId, $rawResult, $filter);
	}

	private function applyStageSemanticFilter(array &$filter, array|string|null $stageSemanticFilter): void
	{
		if ($stageSemanticFilter === null || $stageSemanticFilter === '' || $stageSemanticFilter === [])
		{
			return;
		}

		$stageIds = $this->getStageIdsBySemantics((array)$stageSemanticFilter);
		$filter['@STAGE_ID'] = !empty($stageIds) ? $stageIds : ['__empty_stage_semantic_filter__'];
	}

	private function getStageIdsBySemantics(array $semanticIds): array
	{
		$semanticIds = array_filter(
			$semanticIds,
			static fn(mixed $semanticId): bool => is_scalar($semanticId) && (string)$semanticId !== ''
		);
		$semanticIds = array_map('strval', $semanticIds);
		if (empty($semanticIds))
		{
			return [];
		}

		$stageIds = [];
		foreach ($this->getStageCategoryIdsForSemanticFilter() as $categoryId)
		{
			foreach ($this->factory->getStages($categoryId) as $stage)
			{
				$semantics = $stage->getSemantics();
				if (
					in_array(PhaseSemantics::PROCESS, $semanticIds, true)
					&& !PhaseSemantics::isFinal($semantics)
				)
				{
					$stageIds[] = $stage->getStatusId();
					continue;
				}

				if (is_string($semantics) && in_array($semantics, $semanticIds, true))
				{
					$stageIds[] = $stage->getStatusId();
				}
			}
		}

		return array_values(array_unique($stageIds));
	}

	private function getStageCategoryIdsForSemanticFilter(): array
	{
		$categoryId = $this->getCategoryId();
		if ($categoryId >= 0)
		{
			return [$categoryId];
		}

		if (!$this->factory->isCategoriesSupported())
		{
			return [0];
		}

		$categoryIds = [];
		foreach ($this->factory->getCategories() as $category)
		{
			$categoryIds[] = (int)$category->getId();
		}

		return array_values(array_unique($categoryIds));
	}

	private function unsetFilterKey(array &$filter, string $key): void
	{
		unset(
			$filter[$key],
			$filter['!' . $key],
			$filter['@' . $key],
			$filter['!@' . $key]
		);
	}

	public function canUseAllCategories(): bool
	{
		return true;
	}

	public function isKanbanSupported(): bool
	{
		// ACTIVITIES view groups items by activity-deadline buckets, not by entity stages,
		// so IS_STAGES_ENABLED is irrelevant here. The only prerequisite is counters,
		// matching the tab visibility condition in ItemList::getViews().
		return $this->factory->isCountersEnabled();
	}

	public function isExclusionSupported(): bool
	{
		return false;
	}

	public function isTotalPriceSupported(): bool
	{
		return false;
	}

	public function updateItemStage(int $id, string $stageId, array $newStateParams, array $stages): Result
	{
		$result = $this->getItemViaLoadedItems($id);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $this->changeStageByActivity($stageId, $id);
	}

	public function getRequiredFieldsByStages(array $stages): array
	{
		return [];
	}

	public function getAllowStages(array $filter = []): array
	{
		return array_column($this->getStagesList(), 'STATUS_ID');
	}

	public function isRecurringSupported(): bool
	{
		// In ACTIVITIES view mode recurring templates (IS_RECURRING=Y) must not be filtered out,
		// matching DealActivities behaviour where isRecurringSupported() returns false.
		return false;
	}
}
