<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Tasks\CheckList\Decorator\CheckListMemberDecorator;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\Exception;
use Bitrix\Tasks\V2\Internal\Exception\CheckList\CheckListConflictException;
use Bitrix\Tasks\V2\Internal\Exception\CheckList\CheckListNotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\Pull\Push\Service as PullService;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\CheckListEntityRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\CheckListMapper;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\CheckList\Prepare\Save\CheckListEntityFieldService;
use Bitrix\Tasks\V2\Internal\Service\CheckList\Result\CheckListStatusResult;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;
use Bitrix\Tasks\V2\Public\Command\CheckList\SaveCheckListCommand;
use Bitrix\Tasks\V2\Public\Provider\CheckListProvider;
use InvalidArgumentException;

class CheckListService extends BaseCheckListService
{
	public function __construct(
		private readonly CheckListRepositoryInterface $checkListRepository,
		private readonly CheckListEntityRepositoryInterface $checkListEntityRepository,
		private readonly CheckListEntityFieldService $fieldsService,
		private readonly TaskCheckListFacade $checkListFacade,
		private readonly CheckListMapper $checkListMapper,
		private readonly NodeIdGenerator $nodeIdGenerator,
		private readonly EgressInterface $egressController,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly CheckListRevisionService $checkListRevisionService,
		private readonly PullService $pullService,
		CheckListProvider $checkListProvider,
		CheckListFacadeResolver $checkListFacadeResolver,
		Logger $logger,
	)
	{
		parent::__construct($checkListProvider, $checkListFacadeResolver, $logger);
	}

	public function complete(array $ids, int $userId): Entity\CheckList
	{
		[$newCheckList] = $this->changeItemsStatus(ids: $ids, userId: $userId, isComplete: true);

		return $newCheckList;
	}

	public function renew(array $ids, int $userId): Entity\CheckList
	{
		[$newCheckList] = $this->changeItemsStatus(ids: $ids, userId: $userId, isComplete: false);

		return $newCheckList;
	}

	public function completeStrict(
		array $ids,
		int $userId,
		int $taskId,
		array $taskItemsFields,
		?Entity\CheckList $existingCheckList = null,
		bool $useBatch = true,
	): CheckListStatusResult
	{
		$facade = $this->checkListFacadeResolver->resolveByType($this->getEntityType());
		if ($facade === TaskCheckListFacade::class)
		{
			$facade = TaskCheckListCompletionFacade::class;
		}

		$complete = fn (): CheckListStatusResult => $this->applyItemsStatusStrict(
			facade: $facade,
			ids: $ids,
			userId: $userId,
			isComplete: true,
			entityId: $taskId,
			entityItemsFields: $taskItemsFields,
			existingCheckList: $existingCheckList,
			useBatch: $useBatch,
		);

		$result
			= is_a($facade, TaskCheckListCompletionFacade::class, true)
				? $facade::runDeferred($taskId, $userId, $taskItemsFields, $complete)
				: $complete();

		$this->saveCheckListChange($result, $userId);

		return $result;
	}

	public function add(array $checklists, int $taskId, int $userId, bool $fromAi = false): Entity\Task
	{
		$existingCheckLists = $this->checkListRepository->getByEntity($taskId, Entity\CheckList\Type::Task);

		$checklists = $this->prepareSortIndexes($existingCheckLists, $checklists);

		return $this->save(
			checklists: array_merge($existingCheckLists->toArray(), $checklists),
			taskId: $taskId,
			userId: $userId,
			fromAi: $fromAi,
		)->task;
	}

	public function delete(int $id, int $userId, bool $fromAi = false): void
	{
		$taskId = $this->checkListEntityRepository->getIdByCheckListId($id, Entity\CheckList\Type::Task);

		$entityCheckLists = $this->checkListRepository->getByEntity($taskId, Entity\CheckList\Type::Task);

		/** @var Entity\CheckList\CheckListItem $checkList */
		foreach ($entityCheckLists as $checkList)
		{
			$itemId = $checkList->id;
			$itemParentId = $checkList->parentId;

			if ($itemId === $id || $itemParentId === $id)
			{
				$entityCheckLists->remove($itemId);
			}
		}

		$this->save(
			checklists: $entityCheckLists->toArray(),
			taskId: $taskId,
			userId: $userId,
			fromAi: $fromAi,
		);
	}

	public function addItem(Entity\CheckList\CheckListItem $item, int $userId, bool $fromAi = false): Entity\Task
	{
		if ($item->parentId === null)
		{
			throw new InvalidArgumentException('Parent id must be set');
		}

		if ($item->title === null)
		{
			throw new InvalidArgumentException('Title must be set');
		}

		$taskId = $this->checkListEntityRepository->getIdByCheckListId($item->parentId, Entity\CheckList\Type::Task);

		$existingCheckLists = $this->checkListRepository->getByEntity($taskId, Entity\CheckList\Type::Task);

		/** @var Entity\CheckList\CheckListItem $parent */
		$parent = $existingCheckLists->findOneById($item->parentId);

		$newItem = new Entity\CheckList\CheckListItem(
			nodeId: $this->nodeIdGenerator->generate($item->title . $item->parentId . $taskId),
			title: $item->title,
			creator: $item->creator,
			toggledBy: $item->toggledBy,
			toggledDate: $item->toggledDate,
			accomplices: $item->accomplices,
			auditors: $item->auditors,
			attachments: $item->attachments,
			isComplete: $item->isComplete,
			isImportant: $item->isImportant,
			parentId: $item->parentId,
			parentNodeId: $parent->nodeId,
			sortIndex: $item->sortIndex ?? $this->getNextSortIndex($existingCheckLists, $item->parentId),
			actions: $item->actions,
			collapsed: $item->collapsed,
			expanded: $item->expanded,
		);

		return $this->save(
			checklists: array_merge($existingCheckLists->toArray(), [$newItem->toArray()]),
			taskId: $taskId,
			userId: $userId,
			fromAi: $fromAi,
		)->task;
	}

	public function updateItem(Entity\CheckList\CheckListItem $item, int $userId, bool $fromAi = false): Entity\Task
	{
		$taskId = $this->checkListEntityRepository->getIdByCheckListId($item->id, Entity\CheckList\Type::Task);

		$existingCheckLists = $this->checkListRepository->getByEntity($taskId, Entity\CheckList\Type::Task);

		$updated = false;

		/** @var Entity\CheckList\CheckListItem $existingItem */
		foreach ($existingCheckLists as $existingItem)
		{
			if ($existingItem->id !== $item->id)
			{
				continue;
			}

			$item = new Entity\CheckList\CheckListItem(
				id: $item->id,
				nodeId: $item->nodeId ?? $existingItem->nodeId,
				title: $item->title ?? $existingItem->title,
				creator: $item->creator ?? $existingItem->creator,
				toggledBy: $item->toggledBy ?? $existingItem->toggledBy,
				toggledDate: $item->toggledDate ?? $existingItem->toggledDate,
				accomplices: $item->accomplices ?? $existingItem->accomplices,
				auditors: $item->auditors ?? $existingItem->auditors,
				attachments: $item->attachments ?? $existingItem->attachments,
				isComplete: $item->isComplete ?? $existingItem->isComplete,
				isImportant: $item->isImportant ?? $existingItem->isImportant,
				parentId: $item->parentId ?? $existingItem->parentId,
				parentNodeId: $item->parentNodeId ?? $existingItem->parentNodeId,
				sortIndex: $item->sortIndex ?? $existingItem->sortIndex,
				actions: $item->actions ?? $existingItem->actions,
				collapsed: $item->collapsed ?? $existingItem->collapsed,
				expanded: $item->expanded ?? $existingItem->expanded,
			);

			$existingCheckLists->remove($existingItem->id);
			$existingCheckLists->add($item);

			$updated = true;

			break;
		}

		if (!$updated)
		{
			throw new CheckListNotFoundException();
		}

		return $this->save($existingCheckLists->toArray(), $taskId, $userId, fromAi: $fromAi)->task;
	}

	/**
	 * @throws CheckListConflictException when $baseRevision is provided and does not match the current revision.
	 */
	public function save(
		array $checklists,
		int $taskId,
		int $userId,
		bool $skipNotification = false,
		?string $baseRevision = null,
		bool $fromAi = false,
	): SaveCheckListResult
	{
		$taskBeforeUpdate = $this->taskRepository->getById($taskId);

		if ($taskBeforeUpdate === null)
		{
			throw new Exception('Task not found');
		}

		$existingCheckList = $this->checkListProvider->getByEntity(
			entityId: $taskId,
			userId: $userId,
			type: Entity\CheckList\Type::Task,
		);

		if ($baseRevision === null)
		{
			$revision = $this->checkListRevisionService->bumpRevision($taskId, Entity\CheckList\Type::Task);
		}
		else
		{
			$revision = $this->checkListRevisionService->tryReserveRevision(
				$taskId,
				Entity\CheckList\Type::Task,
				$baseRevision,
			);

			if ($revision === null)
			{
				$currentRevision = $this->checkListRevisionService->getRevision($taskId, Entity\CheckList\Type::Task);

				throw new CheckListConflictException($existingCheckList, $currentRevision);
			}
		}

		$taskBeforeUpdate = $taskBeforeUpdate->cloneWith(['checklist' => $existingCheckList->toArray()]);

		$task = $this->saveChecklists(
			checklists: $checklists,
			taskId: $taskId,
			userId: $userId,
		);

		$pushCheckList = $this->pullService->isEnabled()
			? $this->checkListProvider->getByEntityWithoutUserState($taskId, Entity\CheckList\Type::Task)
			: null;

		$task = $task->cloneWith(['chatId' => $taskBeforeUpdate->chatId]);

		$this->egressController->process(new SaveCheckListCommand(
			task: $task,
			updatedBy: $userId,
			taskBeforeUpdate: $taskBeforeUpdate,
			skipNotification: $skipNotification,
			fromAi: $fromAi,
			pushCheckList: $pushCheckList,
			revision: $revision,
		));

		return new SaveCheckListResult($task, $revision);
	}

	protected function saveChecklists(array $checklists, int $taskId, int $userId): Entity\Task
	{
		$checklists = $this->fieldsService->prepare($checklists, $taskId, Entity\CheckList\Type::Task);

		$items = $this->checkListMapper->mapToNodes($checklists);

		$decorator = $this->getCheckListMemberDecorator($userId);
		$nodes = $decorator->mergeNodes(
			entityId: $taskId,
			nodes: $items,
		);

		return new Entity\Task(
			id: $taskId,
			checklist: $this->checkListMapper->mapToArray($nodes),
		);
	}

	protected function changeItemsStatus(array $ids, int $userId, bool $isComplete): array
	{
		$result = $this->applyItemsStatus($ids, $userId, $isComplete);

		$this->saveCheckListChange($result, $userId);

		return [$result->getCheckList(), $result->getEntityBeforeUpdate()];
	}

	private function saveCheckListChange(CheckListStatusResult $result, int $userId): void
	{
		if (!$result->hasChanges())
		{
			return;
		}

		$taskBeforeUpdate = $result->getEntityBeforeUpdate();

		$taskId = (int)$taskBeforeUpdate->getId();

		$revision = $this->checkListRevisionService->bumpRevision($taskId, Entity\CheckList\Type::Task);

		$pushCheckList = $this->pullService->isEnabled()
			? $this->checkListProvider->getByEntityWithoutUserState($taskId, Entity\CheckList\Type::Task)
			: null;

		$task = new Entity\Task(
			id: $taskBeforeUpdate->getId(),
			checklist: $result->getCheckList()->toArray(),
		);

		$this->egressController->process(new SaveCheckListCommand(
			task: $task,
			updatedBy: $userId,
			taskBeforeUpdate: $taskBeforeUpdate,
			pushCheckList: $pushCheckList,
			revision: $revision,
		));
	}

	protected function getEntity(int $entityId): ?Entity\Task
	{
		return $this->taskRepository->getById($entityId);
	}

	protected function getEntityType(): Entity\CheckList\Type
	{
		return Entity\CheckList\Type::Task;
	}

	protected function getCheckListMemberDecorator(int $userId): CheckListMemberDecorator
	{
		return new CheckListMemberDecorator($this->checkListFacade, $userId);
	}

	private function prepareSortIndexes(Entity\CheckList $existingCheckLists, array $checkListsToAdd): array
	{
		$sortIndex = $this->getNextSortIndex($existingCheckLists) ?? 0;

		return array_map(static function (array $checkList) use (&$sortIndex): array
		{
			$checkList['sortIndex'] = $checkList['sortIndex'] ?? $sortIndex++;

			return $checkList;
		}, $checkListsToAdd);
	}

	private function getNextSortIndex(Entity\CheckList $existingCheckLists, ?int $parentId = null): int
	{
		$sortIndex = 0;

		/** @var Entity\CheckList\CheckListItem $checkList */
		foreach ($existingCheckLists as $checkList)
		{
			$currentParentId = $checkList->parentId;

			if ($parentId !== null && $currentParentId !== $parentId)
			{
				continue;
			}

			$currentSortIndex = $checkList->sortIndex;
			if ($currentSortIndex > $sortIndex)
			{
				$sortIndex = $currentSortIndex;
			}
		}

		return $sortIndex + 1;
	}
}
