<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg\Detector;

use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;

class ChecklistOperationDetector
{
	/**
	 * Detects all checklist operations by comparing before and after states
	 *
	 * @param array|null $checklistBefore
	 * @param array|null $checklistAfter
	 * @return array Array of ChecklistOperation objects
	 */
	public function detectOperations(?array $checklistBefore, ?array $checklistAfter): array
	{
		$operations = [];

		// Handle null cases
		$checklistBefore = $checklistBefore ?? [];
		$checklistAfter = $checklistAfter ?? [];

		// Detect entire checklist additions/deletions first (higher priority)
		$operations = array_merge($operations, $this->detectEntireChecklistOperations($checklistBefore, $checklistAfter));

		// Detect completed checklist
		$operations = array_merge($operations, $this->detectStatusChanges($checklistBefore, $checklistAfter));

		// Detect file additions
		$operations = array_merge($operations, $this->detectFileAdditions($checklistBefore, $checklistAfter));

		return $operations;
	}

	/**
	 * Detects entire checklist additions and deletions
	 */
	private function detectEntireChecklistOperations(array $checklistBefore, array $checklistAfter): array
	{
		$operations = [];

		// Get root checklist items (parentId = 0) from before and after
		$beforeRootItems = array_filter($checklistBefore, fn(array $item): bool => $this->isRootItem($item));
		$afterRootItems = array_filter($checklistAfter, fn(array $item): bool => $this->isRootItem($item));

		$beforeRootIds = array_map(fn(array $item): ?int => $this->getItemId($item), $beforeRootItems);
		$afterRootIds = array_map(fn(array $item): ?int => $this->getItemId($item), $afterRootItems);

		// Detect added checklists
		$addedRootIds = array_diff($afterRootIds, $beforeRootIds);
		foreach ($addedRootIds as $rootId) {
			$rootItem = $this->findItemById($checklistAfter, $rootId);
			if ($rootItem) {
				// Count child items for this checklist
				$childItems = array_filter($checklistAfter, fn(array $item): bool => $this->getParentId($item) === $rootId);
				$itemCount = count($childItems);

				$operations[] = new ChecklistOperation(
					type: NotificationType::ChecklistAdded,
					checklistName: $this->getItemTitle($rootItem),
					itemCount: $itemCount,
					itemId: $this->getItemId($rootItem),
				);
			}
		}

		// Detect deleted checklists
		$deletedRootIds = array_diff($beforeRootIds, $afterRootIds);
		foreach ($deletedRootIds as $rootId) {
			$rootItem = $this->findItemById($checklistBefore, $rootId);
			if ($rootItem) {
				$operations[] = new ChecklistOperation(
					type: NotificationType::ChecklistDeleted,
					checklistName: $this->getItemTitle($rootItem)
				);
			}
		}

		return $operations;
	}

	/**
	 * Detects status changes (completed/unchecked)
	 */
	private function detectStatusChanges(array $checklistBefore, array $checklistAfter): array
	{
		$operations = [];
		$completedItems = [];

		foreach ($checklistAfter as $afterItem)
		{
			$itemId = $this->getItemId($afterItem);
			$beforeItem = $this->findItemById($checklistBefore, $itemId);

			if ($beforeItem && !$this->isRootItem($beforeItem))
			{
				$wasBefore = $this->isItemComplete($beforeItem);
				$isAfter = $this->isItemComplete($afterItem);

				if (!$wasBefore && $isAfter)
				{
					$completedItems[] = $afterItem;
				}
			}
		}

		// Process completed items
		$operations = array_merge($operations, $this->detectCompletedItems($completedItems, $checklistAfter));

		return $operations;
	}

	/**
	 * Detects all checklist completed
	 */
	private function detectCompletedItems(array $completedItems, array $checklistAfter): array
	{
		$operations = [];

		if (empty($completedItems))
		{
			return $operations;
		}

		$groupedByChecklist = $this->groupItemsByCheckLists($checklistAfter, $completedItems);
		foreach ($groupedByChecklist as $checkListId => $checkListItems)
		{
			$checkList = $this->findItemById($checklistAfter, $checkListId);
			if (!$checkList)
			{
				continue;
			}

			$checklistName = $this->getItemTitle($checkList);
			$childItems = $this->getDescendantsByCheckListId($checkListId, $checklistAfter);

			$allItemsCompleted = true;
			foreach ($childItems as $childItem)
			{
				if (!$this->isItemComplete($childItem))
				{
					$allItemsCompleted = false;

					break;
				}
			}

			if ($allItemsCompleted)
			{
				// All items in this checklist were completed - treat as entire checklist completion
				$operations[] = new ChecklistOperation(
					type: NotificationType::ChecklistCompleted,
					checklistName: $checklistName,
					itemId: $checkListId,
				);
			}
		}

		return $operations;
	}

	/**
	 * Detects file additions to checklist items
	 */
	private function detectFileAdditions(array $checklistBefore, array $checklistAfter): array
	{
		$operations = [];
		$itemsWithNewFiles = [];
		$totalNewFiles = 0;

		foreach ($checklistAfter as $afterItem)
		{
			$itemId = $this->getItemId($afterItem);
			$beforeItem = $this->findItemById($checklistBefore, $itemId);

			if ($beforeItem)
			{
				$beforeFiles = $this->getItemAttachments($beforeItem);
				$afterFiles = $this->getItemAttachments($afterItem);

				$newFilesCount = count($afterFiles) - count($beforeFiles);
				if ($newFilesCount > 0) {
					$itemsWithNewFiles[] = $afterItem;
					$totalNewFiles += $newFilesCount;
				}
			}
			else
			{
				// New item with files
				$files = $this->getItemAttachments($afterItem);
				if (!empty($files))
				{
					$itemsWithNewFiles[] = $afterItem;
					$totalNewFiles += count($files);
				}
			}
		}

		if (!empty($itemsWithNewFiles))
		{
			$groupedByChecklist = $this->groupItemsByCheckLists($checklistAfter, $itemsWithNewFiles);

			foreach ($groupedByChecklist as $checkListId => $checkListItems)
			{
				$checkList = $this->findItemById($checklistAfter, $checkListId);
				if (!$checkList)
				{
					continue;
				}

				$operations[] = new ChecklistOperation(
					type: NotificationType::ChecklistFilesAdded,
					checklistName: $this->getItemTitle($checkList),
					itemCount: count($checkListItems),
					itemId: $checkListId,
					itemIds: $this->extractItemIds($checkListItems),
				);
			}
		}

		return $operations;
	}

	// Helper methods
	private function getDescendantsByCheckListId(int $checkListId, array $items): array
	{
		$childrenMap = [];
		foreach ($items as $item)
		{
			$parentId = $this->getParentId($item);
			$childrenMap[$parentId][] = $item;
		}

		return $this->collectDescendantsRecursive($checkListId, $childrenMap);
	}

	private function collectDescendantsRecursive(int $parentId, array $childrenMap): array
	{
		$descendants = [];
		$directChildren = $childrenMap[$parentId] ?? [];

		foreach ($directChildren as $child)
		{
			$descendants[] = $child;

			$childId = $this->getItemId($child);
			$descendants = array_merge(
				$descendants,
				$this->collectDescendantsRecursive($childId, $childrenMap),
			);
		}

		return $descendants;
	}


	private function groupItemsByCheckLists(array $allItems, array $itemsForGroup): array
	{
		$result = [];

		foreach ($itemsForGroup as $item)
		{
			if (!$this->isRootItem($item))
			{
				$rootId = $this->getItemRootId($allItems, $item);
				$result[$rootId] ??= [];
				$result[$rootId][] = $item;
			}
		}

		return $result;
	}

	private function getItemRootId(array $allItems, array $item): int
	{
		if ($this->isRootItem($item))
		{
			return $this->getItemId($item);
		}

		$parentItem = $this->findItemById($allItems, $this->getParentId($item)) ?? [];

		return $this->getItemRootId($allItems, $parentItem);
	}

	private function extractItemIds(array $checklist): array
	{
		return array_map(fn(array $item): ?int => $this->getItemId($item), $checklist);
	}

	private function getItemId(array $item): ?int
	{
		return isset($item['id']) ? (int)$item['id'] : null;
	}

	private function getParentId(array $item): ?int
	{
		return isset($item['parentId']) ? (int)$item['parentId'] : null;
	}

	private function findItemById(array $checklist, ?int $id): ?array
	{
		foreach ($checklist as $item)
		{
			if ($this->getItemId($item) === $id)
			{
				return $item;
			}
		}
		return null;
	}

	private function isItemComplete(array $item): bool
	{
		$isComplete = $item['isComplete'] ?? false;

		// Handle empty string as false, and convert to boolean
		if ($isComplete === '' || $isComplete === null || $isComplete === false)
		{
			return false;
		}

		return (bool)$isComplete;
	}

	private function isRootItem(array $item): bool
	{
		return (int)$this->getParentId($item) === 0;
	}

	private function getItemTitle(array $item): string
	{
		return $item['title'] ?? 'Checklist Item';
	}

	private function getItemAttachments(array $item): array
	{
		$attachments = $item['attachments'] ?? [];

		// Handle different attachment formats
		if (is_array($attachments)) {
			// If it's already an array (old format), return as is
			return $attachments;
		}

		if (is_object($attachments)
			|| (is_array($attachments) && !empty($attachments) && !is_numeric(array_keys($attachments)[0])))
		{
			// If it's an object or associative array (new format), convert to array
			return array_values((array)$attachments);
		}

		return [];
	}
}
