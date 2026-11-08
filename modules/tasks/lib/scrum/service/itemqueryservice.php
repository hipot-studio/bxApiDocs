<?php

namespace Bitrix\Tasks\Scrum\Service;

use Bitrix\Main\UI\PageNavigation;
use Bitrix\Tasks\Scrum\Internal\ItemTable;

final class ItemQueryService
{
	public const MAX_MOVE_ITEMS_COUNT = 500;

	private const SELECTOR_MAX_COUNT = 30;
	private const MAX_ACCESS_CHECK_COUNT = self::MAX_MOVE_ITEMS_COUNT + 1;
	private readonly TaskService $taskService;

	public function __construct(private readonly int $userId, ?TaskService $taskService = null)
	{
		$this->taskService = $taskService ?? new TaskService($userId);
	}

	/**
	 * Returns bounded root Scrum items for an entity selector.
	 *
	 * @param int $entityId Scrum entity id.
	 * @param int[] $ids Included item ids.
	 * @param int[] $excludeIds Excluded item ids.
	 * @param string $searchQuery Item name fragment.
	 * @return array
	 */
	public function getRootItems(
		int $entityId,
		array $ids = [],
		array $excludeIds = [],
		string $searchQuery = '',
	): array
	{
		return $this->findRootItems(
			$entityId,
			$ids,
			$excludeIds,
			$searchQuery,
			self::SELECTOR_MAX_COUNT,
		);
	}

	private function findRootItems(
		int $entityId,
		array $ids,
		array $excludeIds,
		string $searchQuery,
		int $maxCount,
	): array
	{
		if ($this->userId <= 0 || $entityId <= 0)
		{
			return [];
		}

		$filter = [
			'CHECK_PERMISSIONS' => 'Y',
			'ONLY_ROOT_TASKS' => 'N',
			'PARENT_ID' => 0,
			'SCRUM_TASKS' => 'Y',
		];

		if ($ids)
		{
			$sourceIds = $this->getSourceIds($entityId, $ids);
			if (!$sourceIds)
			{
				return [];
			}

			$filter['@ID'] = $sourceIds;
		}
		else
		{
			if ($excludeIds)
			{
				$sourceIds = $this->getSourceIds($entityId, $excludeIds);
				if ($sourceIds)
				{
					$filter['!ID'] = $sourceIds;
				}
			}

			if ($searchQuery !== '')
			{
				$filter['%TITLE'] = $searchQuery;
			}
		}

		$navigation = new PageNavigation('scrum-item-selector-provider');
		$navigation->setCurrentPage(1);
		$navigation->setPageSize($maxCount);

		$taskIds = $this->taskService->getTaskIdsByFilter($filter, $navigation, [$entityId]);
		if (!$taskIds)
		{
			return [];
		}

		return ItemTable::getList([
			'select' => ['ID', 'NAME', 'SORT_FLOAT'],
			'filter' => [
				'=ENTITY_ID' => $entityId,
				'=ACTIVE' => 'Y',
				'@SOURCE_ID' => $taskIds,
			],
			'order' => ['SORT_FLOAT' => 'ASC', 'ID' => 'DESC'],
		])->fetchAll();
	}

	/**
	 * @param int[] $itemIds
	 */
	public function canReadRootItems(int $entityId, array $itemIds): bool
	{
		if ($this->userId <= 0 || $entityId <= 0 || !$itemIds)
		{
			return false;
		}

		foreach ($itemIds as $itemId)
		{
			if (!is_int($itemId) || $itemId <= 0)
			{
				return false;
			}
		}

		$itemIds = array_values(array_unique($itemIds));
		if (count($itemIds) > self::MAX_ACCESS_CHECK_COUNT)
		{
			return false;
		}

		$items = $this->findRootItems(
			$entityId,
			$itemIds,
			[],
			'',
			self::MAX_ACCESS_CHECK_COUNT,
		);
		$accessibleItemIds = array_map('intval', array_column($items, 'ID'));

		sort($itemIds);
		sort($accessibleItemIds);

		return $itemIds === $accessibleItemIds;
	}

	private function getSourceIds(int $entityId, array $itemIds): array
	{
		$items = ItemTable::getList([
			'select' => ['SOURCE_ID'],
			'filter' => [
				'=ENTITY_ID' => $entityId,
				'=ACTIVE' => 'Y',
				'@ID' => $itemIds,
			],
		])->fetchAll();

		return array_map('intval', array_column($items, 'SOURCE_ID'));
	}
}
