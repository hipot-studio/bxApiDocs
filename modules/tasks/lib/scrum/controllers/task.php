<?php

namespace Bitrix\Tasks\Scrum\Controllers;

use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Integration\SocialNetwork\Group;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\Scrum\Service\ItemService;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Completion\CheckListCompletionService;
use Bitrix\Tasks\V2\Internal\Service\Task\Completion\CheckListCompletionSnapshotService;
use Bitrix\Tasks\V2\Internal\Service\Task\Completion\TaskCompletionService;

class Task extends Controller
{
	const ERROR_COULD_NOT_LOAD_MODULE = 'TASKS_STC_01';
	const ERROR_ACCESS_DENIED = 'TASKS_STC_02';
	const ERROR_TASK_COMPLETION_FAILED = 'TASKS_STC_03';

	protected function processBeforeAction(Action $action)
	{
		if ($action->getName() === 'isParentScrumTask')
		{
			return true;
		}

		$actionArguments = $action->getArguments();

		$taskId = (is_numeric($actionArguments['taskId'] ?? null) ? (int) $actionArguments['taskId'] : 0);
		$groupId = (is_numeric($actionArguments['groupId'] ?? null) ? (int) $actionArguments['groupId'] : 0);

		$userId = User::getId();

		if ($taskId && !TaskAccessController::can($userId, ActionDictionary::ACTION_TASK_READ, $taskId))
		{
			$this->addAccessDeniedError();

			return false;
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			$this->errorCollection->setError(
				new Error(
					Loc::getMessage('TASKS_STC_ERROR_INCLUDE_MODULE_ERROR'),
					self::ERROR_COULD_NOT_LOAD_MODULE
				)
			);

			return false;
		}

		if (!$taskId && !Group::canReadGroupTasks($userId, $groupId))
		{
			$this->addAccessDeniedError();

			return false;
		}

		return parent::processBeforeAction($action);
	}

	/**
	 * The method checks whether it is necessary to show the functionality that will decide whether
	 * to complete or continue working with the base task.
	 *
	 * @param int $taskId The parent task id.
	 * @param string $action Action on a task. 'complete' or 'renew'.
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function needUpdateTaskStatusAction(int $taskId, string $action): bool
	{
		$taskId = (int) $taskId;

		$userId = User::getId();

		$action = $action === 'complete'
			? ActionDictionary::ACTION_TASK_COMPLETE
			: ActionDictionary::ACTION_TASK_RENEW
		;

		if (!TaskAccessController::can($userId, $action, $taskId))
		{
			return false;
		}

		$queryObject = TaskTable::getList([
			'filter' => [
				'ID' => $taskId,
			],
			'select' => ['STATUS'],
		]);
		if ($taskData = $queryObject->fetch())
		{
			if (
				!(
					(
						(int)$taskData['STATUS'] === Status::COMPLETED
						&& $action === ActionDictionary::ACTION_TASK_RENEW
					)
					|| (
						(int)$taskData['STATUS'] !== Status::COMPLETED
						&& $action === ActionDictionary::ACTION_TASK_COMPLETE
					)
				)
			)
			{
				return false;
			}
		}

		$isAllChildTasksCompleted = true;
		$queryObject = TaskTable::getList([
			'select' => ['ID', 'STATUS', 'PARENT_ID'],
			'filter' => [
				'PARENT_ID' => $taskId,
			],
			'order' => ['ID' => 'ASC']
		]);
		while ($childTaskData = $queryObject->fetch())
		{
			if ((int)$childTaskData['STATUS'] !== Status::COMPLETED)
			{
				$isAllChildTasksCompleted = false;
			}
		}

		if ($action === ActionDictionary::ACTION_TASK_COMPLETE && $isAllChildTasksCompleted)
		{
			return true;
		}

		if ($action === ActionDictionary::ACTION_TASK_RENEW && !$isAllChildTasksCompleted)
		{
			return true;
		}

		return false;
	}

	/**
	 * The method checks if the parent task is a Scrum task in the same Scrum project.
	 *
	 * @param int $groupId Same group id as the subtask.
	 * @param int $taskId Parent task id.
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function isParentScrumTaskAction(int $groupId, int $taskId): bool
	{
		$queryObject = TaskTable::getList([
			'filter' => [
				'ID' => $taskId,
				'GROUP_ID' => $groupId,
			],
			'select' => ['ID'],
		]);

		return (bool) $queryObject->fetch();
	}

	/**
	 * The method returns data on tasks that are needed to be shown to the user when he makes a decision.
	 *
	 * @param int $groupId Group id.
	 * @param array $taskIds List task ids.
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getTasksAction(int $groupId, array $taskIds, ?int $checkListTaskId = null): array
	{
		$tasks = $this->loadTasks($groupId, $taskIds);
		if ($checkListTaskId === null)
		{
			return $tasks;
		}

		$requestedTaskIds = array_map('intval', $taskIds);
		if (
			!in_array($checkListTaskId, $requestedTaskIds, true)
			|| !array_key_exists($checkListTaskId, $tasks)
			|| !$this->canPerformTaskAction(
				User::getId(),
				ActionDictionary::ACTION_TASK_READ,
				$checkListTaskId,
			)
		)
		{
			$this->addAccessDeniedError();

			return $tasks;
		}

		$tasks[$checkListTaskId]['checkListCompletionSnapshot'] = $this->getCheckListCompletionSnapshot(
			$checkListTaskId,
		);

		return $tasks;
	}

	protected function loadTasks(int $groupId, array $taskIds): array
	{
		$tasks = [];

		$queryObject = TaskTable::getList([
			'filter' => [
				'ID' => $taskIds,
				'GROUP_ID' => $groupId,
			],
			'select' => ['ID', 'TITLE'],
		]);
		while ($taskData = $queryObject->fetch())
		{
			$tasks[$taskData['ID']] = [
				'name' => $taskData['TITLE'],
			];
		}

		return $tasks;
	}

	protected function getCheckListCompletionSnapshot(int $taskId): ?array
	{
		return $this->getService(CheckListCompletionSnapshotService::class)->getByTaskId($taskId);
	}

	public function completeTaskAction(int $taskId, bool $completeWithChecklist = false): bool
	{
		$userId = User::getId();
		if ($this->isTaskCompleted($taskId))
		{
			if (!$this->canPerformTaskAction($userId, ActionDictionary::ACTION_TASK_READ, $taskId))
			{
				$this->addAccessDeniedError();

				return false;
			}

			return true;
		}

		// Both variants share the completion rule, including the required result; only the checklist differs
		if (!$this->canCompleteTaskWithChecklist($taskId, $userId))
		{
			$this->addAccessDeniedError();

			return false;
		}

		if ($completeWithChecklist)
		{
			$completionResult = $this->completeChecklist($taskId, $userId);
			if (!$completionResult->isSuccess())
			{
				$this->addErrors($completionResult->getErrors());

				return false;
			}
		}

		$isCompleted = $this->completeTask($taskId, $userId);
		if (!$isCompleted)
		{
			$this->addError(new Error(
				Loc::getMessage('TASKS_STC_ERROR_COMPLETION_FAILED'),
				self::ERROR_TASK_COMPLETION_FAILED,
			));

			return false;
		}

		return true;
	}

	protected function addAccessDeniedError(): void
	{
		$this->addError(new Error(
			Loc::getMessage('TASKS_STC_ERROR_ACCESS_DENIED'),
			self::ERROR_ACCESS_DENIED,
		));
	}

	protected function isTaskCompleted(int $taskId): bool
	{
		return $this->getTaskCompletionService()->hasFinalStatus($taskId);
	}

	protected function canCompleteTaskWithChecklist(int $taskId, int $userId): bool
	{
		return $this->getTaskCompletionService()->canCompleteWithChecklist($taskId, $userId);
	}

	protected function getTaskCompletionService(): TaskCompletionService
	{
		return $this->getService(TaskCompletionService::class);
	}

	protected function canPerformTaskAction(int $userId, string $action, int $taskId): bool
	{
		return TaskAccessController::can($userId, $action, $taskId);
	}

	protected function completeChecklist(int $taskId, int $userId): Result
	{
		return $this->getService(CheckListCompletionService::class)->completeForTask($taskId, $userId);
	}

	protected function completeTask(int $taskId, int $userId): bool
	{
		$task = \CTaskItem::getInstance($taskId, $userId);
		$task->complete();

		return $this->isTaskCompleted($taskId);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $className
	 * @return T
	 */
	protected function getService(string $className): object
	{
		return Container::getInstance()->get($className);
	}

	public function renewTaskAction(int $taskId): bool
	{
		$taskId = (int) $taskId;

		$userId = User::getId();

		if (!TaskAccessController::can($userId,  ActionDictionary::ACTION_TASK_RENEW, $taskId))
		{
			return false;
		}

		$task = \CTaskItem::getInstance($taskId, $userId);

		$queryObject = \CTasks::getList(
			[],
			['ID' => $taskId, '=STATUS' => Status::COMPLETED],
			['ID'],
			['USER_ID' => $userId]
		);
		if ($queryObject->fetch())
		{
			$task->renew();
		}

		return true;
	}

	/**
	 * The method puts the parent task into a mode in which subtasks are not visible on the sprint kanban.
	 *
	 * @param int $taskId Parent task id.
	 * @return bool
	 */
	public function proceedParentTaskAction(int $taskId): bool
	{
		if (!$this->canPerformTaskAction(User::getId(), ActionDictionary::ACTION_TASK_COMPLETE, $taskId))
		{
			$this->addAccessDeniedError();

			return false;
		}

		$itemService = $this->getItemService();

		$item = $itemService->getItemBySourceId($taskId);
		if ($item->isEmpty())
		{
			return false;
		}

		$item->getInfo()->setVisibilitySubtasks('N');

		return $itemService->changeItem($item);
	}

	protected function getItemService(): ItemService
	{
		return new ItemService();
	}

	/**
	 * The method checks if the task is in the Scrum.
	 *
	 * @param int $taskId Task id.
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function isScrumTaskAction(int $taskId): bool
	{
		$queryObject = TaskTable::getList([
			'filter' => [
				'ID' => $taskId,
			],
			'select' => ['GROUP_ID'],
		]);
		if ($taskData = $queryObject->fetch())
		{
			$group = Workgroup::getById($taskData['GROUP_ID']);

			return ($group && $group->isScrumProject());
		}

		return false;
	}

	/**
	 * The method returns the missing data for the parent task status update operation.
	 *
	 * @param int $taskId Task id.
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getDataAction(int $taskId): array
	{
		$data = [
			'taskId' => $taskId,
		];

		$queryObject = TaskTable::getList([
			'filter' => [
				'ID' => $taskId,
			],
			'select' => ['GROUP_ID', 'PARENT_ID'],
		]);
		if ($taskData = $queryObject->fetch())
		{
			if ($taskData['GROUP_ID'])
			{
				$data['groupId'] = $taskData['GROUP_ID'];
			}

			if ($taskData['PARENT_ID'])
			{
				$data['parentTaskId'] = $taskData['PARENT_ID'];
			}
		}

		return $data;
	}
}
