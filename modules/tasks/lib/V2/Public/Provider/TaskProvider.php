<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\DI\Attribute\Inject;
use Bitrix\Tasks\Flow\Access\FlowAction;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactory;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;

class TaskProvider
{
	public function __construct(
		private readonly TaskReadRepositoryInterface $taskRepository,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly EgressInterface $egressController,
		#[Inject('tasks.access.controller.factory')]
		private readonly ControllerFactory $controllerFactory,
	)
	{
	}

	public function getTaskById(TaskParams $taskParams): ?Task
	{
		if ($taskParams->checkTaskAccess)
		{
			$controller = $this->controllerFactory->create(Type::Task, $taskParams->userId);
			if (!$controller?->checkByItemId(ActionDictionary::ACTION_TASK_READ, $taskParams->taskId))
			{
				return null;
			}
		}

		$select = new Select(
			group: $taskParams->group,
			flow: $taskParams->flow,
			stage: $taskParams->stage,
			members: $taskParams->members,
			checkLists: $taskParams->checkLists,
			chat: $taskParams->chat,
		);

		$task = $this->taskRepository->getById(
			id: $taskParams->taskId,
			select: $select,
		);

		if ($task === null)
		{
			return null;
		}

		$task = $this->prepareGroup($taskParams, $task);
		$task = $this->prepareFlow($taskParams, $task);

		if (FormV2Feature::isOn('miniform') && !FormV2Feature::isOn())
		{
			return $task;
		}

		if ($task->chatId === null)
		{
			$updatedTask = $this->egressController->createChatForExistingTask($task);

			$this->chatRepository->save(
				chatId: $updatedTask->chatId,
				taskId: $task->getId(),
			);

			return $updatedTask;
		}

		return $task;
	}

	private function prepareFlow(TaskParams $taskParams, Task $task): Task
	{
		if (!$taskParams->flow || !$taskParams->checkFlowAccess || !$task->flow)
		{
			return $task;
		}

		$controller = $this->controllerFactory->create(Type::Flow, $taskParams->userId);
		if ($controller?->checkByItemId(FlowAction::READ->value, $task->flow->getId()))
		{
			return $task;
		}

		// no allowed data for flow
		return $task->cloneWith(['flow' => null]);
	}

	private function prepareGroup(TaskParams $taskParams, Task $task): Task
	{
		if (!$taskParams->group || !$taskParams->checkGroupAccess || !$task->group)
		{
			return $task;
		}

		$controller = $this->controllerFactory->create(Type::Group, $taskParams->userId);
		if ($controller?->checkByItemId(GroupDictionary::VIEW, $task->group->getId()))
		{
			return $task;
		}

		// only allowed data
		$group = new Group(
			id: $task->group->getId(),
			name: $task->group->name,
			image: $task->group->image,
			type: $task->group->type,
		);

		return $task->cloneWith(['group' => $group->toArray()]);
	}
}
