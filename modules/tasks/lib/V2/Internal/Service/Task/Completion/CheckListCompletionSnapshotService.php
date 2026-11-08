<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Completion;

use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;

/**
 * Server-side counterpart of buildCompletionSnapshot() from
 * install/js/tasks/v2/lib/check-list-completion-prompt. Both must produce the same shape,
 * because the prompt dialog renders either of them: groups[{id,title,completedCount,totalCount,
 * items[{id,rootId,title,depth,isContext}]}], incompleteIds[], firstIncompleteId.
 */
class CheckListCompletionSnapshotService
{
	public function getByTaskId(int $taskId): ?array
	{
		return $this->build(array_values($this->getItemsByTaskId($taskId)));
	}

	public function getItemsByTaskId(int $taskId): array
	{
		return TaskCheckListFacade::getList(
			['ID', 'PARENT_ID', 'TITLE', 'IS_COMPLETE', 'SORT_INDEX'],
			['TASK_ID' => $taskId],
			['SORT_INDEX' => 'ASC', 'ID' => 'ASC'],
		);
	}

	public function getFullItemsByTaskId(int $taskId): array
	{
		return TaskCheckListFacade::getByEntityId($taskId);
	}

	public function build(array $items): ?array
	{
		$itemsById = [];
		$childrenByParentId = [];
		foreach ($items as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$id = (int)($item['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}

			$normalizedItem = [
				'ID' => $id,
				'PARENT_ID' => (int)($item['PARENT_ID'] ?? 0),
				'TITLE' => (string)($item['TITLE'] ?? ''),
				'IS_COMPLETE' => in_array(($item['IS_COMPLETE'] ?? null), ['Y', true], true),
				'SORT_INDEX' => (int)($item['SORT_INDEX'] ?? 0),
			];
			$itemsById[$id] = $normalizedItem;
			$childrenByParentId[$normalizedItem['PARENT_ID']][] = $normalizedItem;
		}

		foreach ($childrenByParentId as &$children)
		{
			usort($children, [$this, 'compareItems']);
		}
		unset($children);

		$groups = [];
		$incompleteIds = [];
		foreach ($childrenByParentId[0] ?? [] as $root)
		{
			$descendants = [];
			$this->walk(
				$root['ID'],
				$childrenByParentId,
				0,
				$descendants,
				[$root['ID'] => true],
			);

			$incomplete = array_filter(
				$descendants,
				static fn (array $descendant): bool => !$descendant['item']['IS_COMPLETE'],
			);
			if (empty($incomplete))
			{
				continue;
			}

			$includedIds = [];
			foreach ($incomplete as $descendant)
			{
				$includedIds[$descendant['item']['ID']] = true;
			}

			foreach ($incomplete as $descendant)
			{
				$parentId = $descendant['item']['PARENT_ID'];
				$visitedParentIds = [];
				while (
					$parentId !== 0
					&& $parentId !== $root['ID']
					&& !isset($includedIds[$parentId])
					&& !isset($visitedParentIds[$parentId])
				)
				{
					$visitedParentIds[$parentId] = true;
					$includedIds[$parentId] = true;
					$parentId = $itemsById[$parentId]['PARENT_ID'] ?? 0;
				}
			}

			$groupItems = [];
			foreach ($descendants as $descendant)
			{
				$item = $descendant['item'];
				if (!isset($includedIds[$item['ID']]))
				{
					continue;
				}

				$groupItems[] = [
					'id' => $item['ID'],
					'rootId' => $root['ID'],
					'title' => $item['TITLE'],
					'depth' => $descendant['depth'],
					'isContext' => $item['IS_COMPLETE'],
				];
			}

			$groupIncompleteIds = array_map(
				static fn (array $descendant): int => $descendant['item']['ID'],
				$incomplete,
			);
			array_push($incompleteIds, ...$groupIncompleteIds);
			$groups[] = [
				'id' => $root['ID'],
				'title' => $root['TITLE'],
				'completedCount' => count($descendants) - count($incomplete),
				'totalCount' => count($descendants),
				'items' => $groupItems,
			];
		}

		if (empty($groups))
		{
			return null;
		}

		return [
			'groups' => $groups,
			'incompleteIds' => $incompleteIds,
			'firstIncompleteId' => $incompleteIds[0] ?? null,
		];
	}

	private function walk(
		int $parentId,
		array $childrenByParentId,
		int $depth,
		array &$result,
		array $visitedIds,
	): void
	{
		foreach ($childrenByParentId[$parentId] ?? [] as $item)
		{
			$id = $item['ID'];
			if (isset($visitedIds[$id]))
			{
				continue;
			}

			$visitedIds[$id] = true;
			$result[] = [
				'item' => $item,
				'depth' => $depth,
			];
			$this->walk($id, $childrenByParentId, $depth + 1, $result, $visitedIds);
		}
	}

	private function compareItems(array $first, array $second): int
	{
		return $first['SORT_INDEX'] <=> $second['SORT_INDEX']
			?: $first['ID'] <=> $second['ID'];
	}
}
