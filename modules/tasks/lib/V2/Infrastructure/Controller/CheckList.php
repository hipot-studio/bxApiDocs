<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\V2\Public\Command\CheckList\CollapseCheckListCommand;
use Bitrix\Tasks\V2\Public\Command\CheckList\CompleteCheckListItemsCommand;
use Bitrix\Tasks\V2\Public\Command\CheckList\ExpandCheckListCommand;
use Bitrix\Tasks\V2\Public\Command\CheckList\RenewCheckListItemsCommand;
use Bitrix\Tasks\V2\Public\Command\CheckList\SaveCheckListCommand;
use Bitrix\Tasks\V2\Public\Command\CheckList\SetHideCompletedCommand;
use Bitrix\Tasks\V2\Internal\Access\CheckList\Permission;
use Bitrix\Tasks\V2\Internal\Access\Task;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListRevisionService;
use Bitrix\Tasks\V2\Public\Provider\CheckListProvider;

class CheckList extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.CheckList.get
	 */
	#[CloseSession]
	public function getAction(
		#[Task\Permission\Read]
		Entity\Task $task,
		CheckListProvider $checkListProvider,
		CheckListRevisionService $checkListRevisionService,
	): ?array
	{
		$attemptsLeft = 3;

		do
		{
			$revision = $checkListRevisionService->getRevision($task->getId(), Entity\CheckList\Type::Task);

			$checkList = $checkListProvider->getByEntity(
				$task->getId(),
				$this->userId,
				Entity\CheckList\Type::Task
			);

			$revisionAfter = $checkListRevisionService->getRevision($task->getId(), Entity\CheckList\Type::Task);
		}
		while ($revision !== $revisionAfter && --$attemptsLeft > 0);

		return [
			'status' => 'success',
			'revision' => $revision,
			'checklist' => $checkList->toArray(),
		];
	}

	/**
	 * @ajaxAction tasks.V2.CheckList.save
	 */
	public function saveAction(
		#[Permission\Save]
		Entity\Task $task,
		CheckListProvider $checkListProvider,
		bool $skipNotification = false,
		?string $baseRevision = null,
	): ?array
	{
		if (
			empty($task->checklist)
			&& !TaskAccessController::can(
				userId: $this->userId,
				action: ActionDictionary::ACTION_CHECKLIST_EDIT,
				itemId: $task->getId(),
			)
		)
		{
			return null;
		}

		$result = (new SaveCheckListCommand(
			task: $task,
			updatedBy: $this->userId,
			useConsistency: true,
			skipNotification: $skipNotification,
			baseRevision: $baseRevision,
		))->run();

		if ((bool)$result->getDataByKey('conflict'))
		{
			/** @var Entity\CheckList $conflictCheckList */
			$conflictCheckList = $result->getDataByKey('conflictCheckList');
			$conflictRevision = (string)$result->getDataByKey('conflictRevision');

			return [
				'status' => 'conflict',
				'revision' => $conflictRevision,
				'checklist' => $conflictCheckList->toArray(),
			];
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$nodeIdByServerId = [];
		foreach (($result->getObject()?->checklist ?? []) as $mergedItem)
		{
			$serverId = (int)($mergedItem['id'] ?? 0);
			$nodeId = (string)($mergedItem['nodeId'] ?? '');
			if ($serverId > 0 && $nodeId !== '')
			{
				$nodeIdByServerId[$serverId] = $nodeId;
			}
		}

		$savedCheckList = $checkListProvider->getByEntity(
			$task->getId(),
			$this->userId,
			Entity\CheckList\Type::Task,
			$nodeIdByServerId,
		);

		return [
			'status' => 'success',
			'revision' => (string)$result->getDataByKey('revision'),
			'checklist' => $savedCheckList->toArray(),
		];
	}

	/**
	 * @ajaxAction tasks.V2.CheckList.complete
	 */
	public function completeAction(
		#[Permission\Toggle]
		Entity\Task $task,
	): ?array
	{
		$result = (new CompleteCheckListItemsCommand(
			ids: array_column($task->checklist, 'id'),
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'status' => 'success',
		];
	}

	/**
	 * @ajaxAction tasks.V2.CheckList.renew
	 */
	public function renewAction(
		#[Permission\Toggle]
		Entity\Task $task,
	): ?array
	{
		$result = (new RenewCheckListItemsCommand(
			ids: array_column($task->checklist, 'id'),
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'status' => 'success',
		];
	}

	/**
	 * @ajaxAction tasks.V2.CheckList.collapse
	 */
	public function collapseAction(int $taskId, int $checkListId): bool
	{
		if (!TaskAccessController::can($this->userId, ActionDictionary::ACTION_TASK_READ, $taskId))
		{
			$this->addErrors([new Error('Access denied')]);

			return false;
		}

		$result = (new CollapseCheckListCommand(
			checkListId: $checkListId,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.CheckList.expand
	 */
	public function expandAction(int $taskId, int $checkListId): bool
	{
		if (!TaskAccessController::can($this->userId, ActionDictionary::ACTION_TASK_READ, $taskId))
		{
			$this->addErrors([new Error('Access denied')]);

			return false;
		}

		$result = (new ExpandCheckListCommand(
			checkListId: $checkListId,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.CheckList.setHideCompleted
	 */
	#[CloseSession]
	public function setHideCompletedAction(bool $hide): bool
	{
		$result = (new SetHideCompletedCommand(
			hide: $hide,
			userId: $this->userId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return false;
		}

		return true;
	}
}
