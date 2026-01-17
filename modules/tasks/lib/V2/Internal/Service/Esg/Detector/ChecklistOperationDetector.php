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
		
		// Detect added items
		$operations = array_merge($operations, $this->detectAddedItems($checklistBefore, $checklistAfter));

		// Detect deleted items
		$operations = array_merge($operations, $this->detectDeletedItems($checklistBefore, $checklistAfter));

		// Detect modified items
		$operations = array_merge($operations, $this->detectModifiedItems($checklistBefore, $checklistAfter));
		
		// Detect completed/unchecked items
		$operations = array_merge($operations, $this->detectStatusChanges($checklistBefore, $checklistAfter));
		
		// Detect auditor/accomplice assignments
		$operations = array_merge($operations, $this->detectMemberAssignments($checklistBefore, $checklistAfter));
		
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
	 * Detects newly added checklist items (skip added checklists)
	 */
	private function detectAddedItems(array $checklistBefore, array $checklistAfter): array
	{
		$operations = [];
		$beforeIds = $this->extractItemIds($checklistBefore);
		$afterIds = $this->extractItemIds($checklistAfter);
		
		$addedIds = array_diff($afterIds, $beforeIds);

		$addedRoots = $this->getAddedRootIds($checklistBefore, $checklistAfter);

		if (!empty($addedIds))
		{
			$addedItems = array_filter($checklistAfter, fn(array $item): bool => in_array($this->getItemId($item), $addedIds));

			if (!empty($addedItems))
			{
				$groupedItemsByCheckLists = $this->groupItemsByCheckLists($checklistAfter, $addedItems);

				foreach ($groupedItemsByCheckLists as $checkListId => $checkListItems)
				{
					if (empty($checkListItems) || in_array($checkListId, $addedRoots))
					{
						continue;
					}

					$checklistName = $this->getChecklistNameFromItems($checklistAfter, $checkListItems);

					$operations[] = new ChecklistOperation(
						type: NotificationType::ChecklistItemsAdded,
						checklistName: $checklistName,
						itemCount: count($checkListItems),
						itemId: $checkListId,
						itemIds: $this->extractItemIds($checkListItems),
					);
				}
			}
		}
		
		return $operations;
	}
	
	/**
	 * Detects deleted checklist items
	 */
	private function detectDeletedItems(array $checklistBefore, array $checklistAfter): array
	{
		$operations = [];
		$beforeIds = $this->extractItemIds($checklistBefore);
		$afterIds = $this->extractItemIds($checklistAfter);
		
		$deletedIds = array_diff($beforeIds, $afterIds);

		$remainingRootIds = $this->getRootIds($checklistAfter);

		if (!empty($deletedIds))
		{
			$deletedItems = array_filter($checklistBefore, fn(array $item): bool => in_array($this->getItemId($item), $deletedIds));

			$groupedItemsByCheckLists = $this->groupItemsByCheckLists($checklistBefore, $deletedItems);

			foreach ($groupedItemsByCheckLists as $checkListId => $checkListItems)
			{
				if (empty($checkListItems) || !in_array($checkListId, $remainingRootIds))
				{
					continue;
				}

				$checklistName = $this->getChecklistNameFromItems($checklistBefore, $checkListItems);

				$operations[] = new ChecklistOperation(
					type: NotificationType::ChecklistItemsDeleted,
					checklistName: $checklistName,
					itemCount: count($checkListItems),
					itemId: $checkListId,
				);
			}
		}
		
		return $operations;
	}
	
	/**
	 * Detects modified checklist items (title changes, co-executor changes, observer changes)
	 */
	private function detectModifiedItems(array $checklistBefore, array $checklistAfter): array
	{
		$operations = [];
		$modifiedItems = [];
		
		foreach ($checklistAfter as $afterItem)
		{
			$itemId = $this->getItemId($afterItem);
			$beforeItem = $this->findItemById($checklistBefore, $itemId);
			
			if ($beforeItem && $this->isItemModified($beforeItem, $afterItem))
			{
				$modifiedItems[] = $afterItem;
			}
		}

		if (!empty($modifiedItems))
		{
			$groupedByCheckList = $this->groupItemsByCheckLists($checklistAfter, $modifiedItems);

			foreach ($groupedByCheckList as $checkListId => $checkListItems)
			{
				$checklistName = $this->getChecklistNameFromItems($checklistAfter, $checkListItems);

				$operations[] = new ChecklistOperation(
					type: NotificationType::ChecklistItemsModified,
					checklistName: $checklistName,
					itemCount: count($checkListItems),
					itemId: $checkListId,
					itemIds: $this->extractItemIds($checkListItems),
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
		$uncheckedItems = [];
		
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
				elseif ($wasBefore && !$isAfter)
				{
					$uncheckedItems[] = $afterItem;
				}
			}
		}
		
		// Process completed items
		$operations = array_merge($operations, $this->detectCompletedItems($completedItems, $checklistAfter));
		
		// Process unchecked items
		$operations = array_merge($operations, $this->detectUncheckedItems($uncheckedItems, $checklistAfter));
		
		return $operations;
	}
	
	/**
	 * Detects completed items and distinguishes between single and multiple operations
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
			else
			{
				$operation = $this->createOperationByItemCount(
					checkList: $checkList,
					checkListItems: $checkListItems,
					singleItemType: NotificationType::ChecklistSingleItemCompleted,
					multipleItemType: NotificationType::ChecklistItemsCompleted,
				);

				if ($operation)
				{
					$operations[] = $operation;
				}
			}
		}
		
		return $operations;
	}
	
	/**
	 * Detects unchecked items and distinguishes between single and multiple operations
	 */
	private function detectUncheckedItems(array $uncheckedItems, array $checklistAfter): array
	{
		$operations = [];

		if (empty($uncheckedItems))
		{
			return $operations;
		}

		$groupedByChecklist = $this->groupItemsByCheckLists($checklistAfter, $uncheckedItems);
		foreach ($groupedByChecklist as $checkListId => $checkListItems)
		{
			$checkList = $this->findItemById($checklistAfter, $checkListId);
			if (!$checkList)
			{
				continue;
			}

			$operation = $this->createOperationByItemCount(
				checkList: $checkList,
				checkListItems: $checkListItems,
				singleItemType: NotificationType::ChecklistSingleItemUnchecked,
				multipleItemType: NotificationType::ChecklistItemsUnchecked,
			);

			if ($operation)
			{
				$operations[] = $operation;
			}
		}

		return $operations;
	}

	private function createOperationByItemCount(
		array $checkList,
		array $checkListItems,
		NotificationType $singleItemType,
		NotificationType $multipleItemType,
	): ?ChecklistOperation
	{
		if (empty($checkListItems))
		{
			return null;
		}

		$itemCount = count($checkListItems);

		return match ($itemCount)
		{
			1 => new ChecklistOperation(
					type: $singleItemType,
					checklistName: $this->getItemTitle($checkList),
					itemCount: $itemCount,
					itemName: $this->getItemTitle(reset($checkListItems)),
					itemId: $this->getItemId($checkList),
					itemIds: $this->extractItemIds($checkListItems),
				),
			default => new ChecklistOperation(
					type: $multipleItemType,
					checklistName: $this->getItemTitle($checkList),
					itemCount: $itemCount,
					itemId: $this->getItemId($checkList),
					itemIds: $this->extractItemIds($checkListItems),
				),
		};
	}
	
	/**
	 * Detects member assignments (auditors and accomplices)
	 * Note: Co-executor and observer changes are now handled as ITEMS_MODIFIED operations
	 * This method is kept for backward compatibility but returns empty array
	 */
	private function detectMemberAssignments(array $checklistBefore, array $checklistAfter): array
	{
		// Co-executor and observer changes are now classified as ITEMS_MODIFIED operations
		// and are handled in the detectModifiedItems method
		return [];
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

	private function getAddedRootIds(array $checkListBefore, array $checkListAfter): array
	{
		$afterRootIds = $this->getRootIds($checkListAfter);
		$beforeRootIds = $this->getRootIds($checkListBefore);

		return array_diff($afterRootIds, $beforeRootIds);
	}

	private function getRootIds(array $checkList): array
	{
		$rootItems = array_filter($checkList, fn(array $item): bool => $this->isRootItem($item));
		$rootIds = $this->extractItemIds($rootItems);

		return $rootIds;
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
	
	private function isItemModified(array $beforeItem, array $afterItem): bool
	{
		// Check if title changed
		$beforeTitle = $beforeItem['title'] ?? '';
		$afterTitle = $afterItem['title'] ?? '';
		
		if ($beforeTitle !== $afterTitle) {
			return true;
		}
		
		// Check if co-executors (accomplices) changed
		if ($this->hasMemberChanges($beforeItem, $afterItem, 'accomplices')) {
			return true;
		}
		
		// Check if observers (auditors) changed
		if ($this->hasMemberChanges($beforeItem, $afterItem, 'auditors')) {
			return true;
		}
		
		return false;
	}
	
	private function getItemTitle(array $item): string
	{
		return $item['title'] ?? 'Checklist Item';
	}
	
	/**
	 * Gets the checklist name from the parent checklist based on child items
	 * This method finds the parent checklist name for child items
	 */
	private function getChecklistNameFromItems(array $allItems, array $childItems): string
	{
		if (empty($childItems))
		{
			return 'Checklist';
		}
		
		// Get the parent ID from the first child item
		$firstChild = reset($childItems);
		$parentId = (int)$this->getParentId($firstChild);

		if ($parentId === 0)
		{
			return $this->getItemTitle($firstChild);
		}

		$nextItem = $this->findItemById($allItems, $parentId);

		return $this->getChecklistNameFromItems($allItems, [$nextItem]);
	}
	
	private function getItemMembers(array $item, string $memberType): array
	{
		// Handle the old format (accomplices/auditors arrays)
		if (isset($item[$memberType]))
		{
			return $item[$memberType];
		}
		
		// Handle the new format (members object with type property)
		if (isset($item['members']))
		{
			$members = [];
			foreach ($item['members'] as $member)
			{
				// Check if this member matches the requested type
				// Try multiple possible type values and formats
				$memberTypeValue = $member['type'] ?? $member['memberType'] ?? '';

				$accomplicesTypes = ['A', 'accomplice'];
				$auditorsTypes = ['U', 'O', 'auditor'];
				
				if ($memberType === 'accomplices' && in_array($memberTypeValue, $accomplicesTypes, true))
				{
					$members[] = $member;
				}
				elseif ($memberType === 'auditors' && in_array($memberTypeValue, $auditorsTypes, true))
				{
					$members[] = $member;
				}
			}
			return $members;
		}
		
		// Handle direct member arrays with different keys
		$alternativeKeys = [
			'accomplices' => ['accomplices'],
			'auditors' => ['auditors', 'watchers']
		];
		
		if (isset($alternativeKeys[$memberType])) {
			foreach ($alternativeKeys[$memberType] as $key) {
				if (isset($item[$key])) {
					return $item[$key];
				}
			}
		}
		
		return [];
	}
	
	/**
	 * Checks if there are changes in members (accomplices or auditors) for an item
	 */
	private function hasMemberChanges(array $beforeItem, array $afterItem, string $memberType): bool
	{
		$beforeMembers = $this->getItemMembers($beforeItem, $memberType);
		$afterMembers = $this->getItemMembers($afterItem, $memberType);
		
		$beforeIds = array_map(fn(array $member): int => $member['id'] ?? 0, $beforeMembers);
		$afterIds = array_map(fn(array $member): int => $member['id'] ?? 0, $afterMembers);

		// Sort arrays to ensure consistent comparison
		sort($beforeIds);
		sort($afterIds);
		
		return $beforeIds !== $afterIds;
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
