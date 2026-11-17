<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Validation\ValidationException;
use Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskListRequest;
use Bitrix\Tasks\V2\Infrastructure\Controller\Response\TaskListResponse;
use Bitrix\Tasks\V2\Public\Command\Task\AddTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\DeleteTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\UpdateTaskCommand;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Service\Interaction\InteractionOrigin;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskList\TaskListParams;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class Task extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.get
	 */
	#[CloseSession]
	public function getAction(
		TaskProvider $taskProvider,
		TaskParams $taskSelect,
		#[Permission\Read(returnTrueIfNotExist: true)]
		?Entity\Task $task = null,
		bool $view = true,
	): ?Entity\Task
	{
		if ($this->requestHasNonArrayTask())
		{
			$this->addInvalidTaskIdError();

			return null;
		}

		if (!$task instanceof Entity\Task)
		{
			$this->addInvalidTaskIdError();

			return null;
		}

		return $taskProvider->get(
			new TaskParams(
				taskId: $task->getId(),
				userId: $this->userId,
				group: $taskSelect->group,
				flow: $taskSelect->flow,
				stage: $taskSelect->stage,
				members: $taskSelect->members,
				checkLists: $taskSelect->checkLists,
				tags: $taskSelect->tags,
				crm: $taskSelect->crm,
				subTasks: $taskSelect->subTasks,
				relatedTasks: $taskSelect->relatedTasks,
				gantt: $taskSelect->gantt,
				placements: $taskSelect->placements,
				containsCommentFiles: $taskSelect->containsCommentFiles,
				favorite: $taskSelect->favorite,
				options: $taskSelect->options,
				parameters: $taskSelect->parameters,
				results: $taskSelect->results,
				userFields: $taskSelect->userFields,
				checkTaskAccess: false,
				view: $view,
				templateReplication: $taskSelect->templateReplication,
			),
		);
	}

	/**
	 * @ajaxAction tasks.V2.Task.add
	 */
	public function addAction(
		#[Permission\Add]
		Entity\Task $task,
		TaskProvider $taskProvider,
		bool $view = false,
		// Nullable so an absent marker (mobile app, legacy, REST) stays
		// distinct from an explicit false. Bound like $view above from the request.
		?bool $deadlineManuallySet = null,
	): ?Arrayable
	{
		$config = new AddConfig(
			userId: $this->userId,
			useConsistency: true,
			manualDeadline: $deadlineManuallySet,
			origin: InteractionOrigin::Ui,
		);

		$result = (new AddTaskCommand(
			task: $task,
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(
			new TaskParams(
				taskId: $result->getId(),
				userId: $this->userId,
				view: $view,
			),
		);
	}

	/**
	 * @ajaxAction tasks.V2.Task.update
	 */
	public function updateAction(
		TaskProvider $taskProvider,
		#[Permission\Update]
		?Entity\Task $task = null,
	): ?Arrayable
	{
		if (!$task instanceof Entity\Task)
		{
			if ($this->getErrors() === [])
			{
				$this->addError(
					new Error(
						'Task ID must be a positive integer',
						'INVALID_TASK_ID',
					),
				);
			}

			return null;
		}

		$config = new UpdateConfig(
			userId: $this->userId,
			useConsistency: true,
			origin: InteractionOrigin::Ui,
		);

		$result = (new UpdateTaskCommand(
			task: $task,
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $taskProvider->get(new TaskParams(taskId: $result->getId(), userId: $this->userId));
	}

	/**
	 * @ajaxAction tasks.V2.Task.delete
	 */
	public function deleteAction(
		#[Permission\Delete]
		Entity\Task $task,
	): ?bool
	{
		$config = new DeleteConfig(
			userId: $this->userId,
			useConsistency: true,
		);

		$result = (new DeleteTaskCommand(
			taskId: $task->getId(),
			config: $config,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Task.list
	 * @throws ArgumentException
	 * @throws ValidationException
	 */
	#[CloseSession]
	public function listAction(
		TaskListRequest $listRequest,
		TaskProvider $taskProvider,
	): array
	{
		$tasksListParams = new TaskListParams(
			userId: $this->userId,
			pagination: $listRequest->pagination->prepare(),
			filter: $listRequest->filter->prepare(),
			sort: $listRequest->order->prepare(),
			select: $listRequest->select->prepare(),
		);

		return (new TaskListResponse(
			$taskProvider->getList($tasksListParams),
			$listRequest->select,
			fn() => $taskProvider->getCount(
				new TaskListParams(
					userId: $this->userId,
					pagination: new Pager(),
					filter: $listRequest->filter->prepare(),
				),
			),
		))->toArray();
	}

	private function requestHasNonArrayTask(): bool
	{
		$request = $this->getRequest();
		$payload = array_merge(
			$request->toArray(),
			$request->getJsonList()->toArray(),
		);

		return array_key_exists('task', $payload) && !is_array($payload['task']);
	}

	private function addInvalidTaskIdError(): void
	{
		if ($this->getErrors() !== [])
		{
			return;
		}

		$this->addError(
			new Error(
				'Task ID must be a positive integer',
				'INVALID_TASK_ID',
			),
		);
	}
}
