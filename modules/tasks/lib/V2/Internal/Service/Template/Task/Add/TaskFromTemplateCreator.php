<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Add;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\Task\CopyFileService;
use Bitrix\Tasks\V2\Internal\Service\AddTaskService;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListService;
use Bitrix\Tasks\V2\Internal\Service\Task;
use Bitrix\Tasks\V2\Internal\Service\Task\MultiTaskService;
use Bitrix\Tasks\V2\Internal\Service\Template\Recent\UpdateTemplateRecentMessage;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Add\Config\AddTaskConfig;
use Bitrix\Tasks\V2\Public\Provider\CheckListProvider;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class TaskFromTemplateCreator
{
	public function __construct(
		private readonly TaskAccessService $taskAccessService,
		private readonly AddTaskService $addTaskService,
		private readonly TaskProvider $taskProvider,
		private readonly CopyFileService $copyFileService,
		private readonly MultiTaskService $multiTaskService,
		private readonly AddSubTasks $addSubTasks,
		private readonly CheckListService $checkListService,
		private readonly CheckListProvider $checkListProvider,
	)
	{

	}

	/**
	 * @throws TaskNotExistsException
	 * @throws TaskAddException
	 */
	public function add(
		Entity\Task $taskData,
		Entity\Template $template,
		AddTaskConfig $config,
	): Entity\Task
	{
		$taskData = $this->prepareChecklist($taskData, $template);
		$this->ensureCanSaveForEveryResponsible($taskData, $config->userId);

		$preparedTaskData = $this->prepareAttachments($taskData, $config);

		$task = $this->addTaskService->add(
			task: $preparedTaskData,
			config: new Task\Action\Add\Config\AddConfig(userId: $config->userId),
		);
		if (!empty($preparedTaskData->checklist))
		{
			$this->checkListService->save(
				checklists: $preparedTaskData->checklist,
				taskId: (int)$task->id,
				userId: $config->userId,
			);
			// A null snapshot makes CopyCheckListService copy the persisted tree with newly assigned IDs.
			$task = $task->cloneWith(['checklist' => null]);
		}
		$multiTasks = $this->multiTaskService->add(
			rootTask: $task,
			multiResponsibleIds: $preparedTaskData->multiResponsibles?->getIdList() ?? [],
			userId: $config->userId,
		);

		if ($config->withSubTasks)
		{
			($this->addSubTasks)($task->id, $template->id, $config);

			foreach ($multiTasks as $multiTask)
			{
				($this->addSubTasks)($multiTask->id, $template->id, $config);
			}
		}

		$task = $this->taskProvider->get(
			new TaskParams(
				taskId: $task->id,
				userId: $config->userId,
				view: $config->view,
			),
		);

		if ($task === null)
		{
			throw new TaskNotExistsException(
				Loc::getMessage('TASKS_CREATE_TASK_FROM_TEMPLATE_TASK_NOT_FOUND')
			);
		}

		if ($task->flow === null)
		{
			(new UpdateTemplateRecentMessage(
				userId: $config->userId,
				templateId: $template->id,
				action: UpdateTemplateRecentMessage::ACTION_ADD,
			))->sendByInternalQueueId();
		}

		return $task;
	}

	private function prepareChecklist(Entity\Task $taskData, Entity\Template $template): Entity\Task
	{
		if (!$this->isChecklistIdSnapshot($taskData->checklist))
		{
			if (empty($taskData->checklist))
			{
				return $taskData;
			}

			// A list that is not entirely items is a web card draft with ids of unsaved items:
			// the card saves its checklist itself by a separate request, so it is treated as not passed
			foreach ($taskData->checklist as $item)
			{
				if (!is_array($item))
				{
					return $taskData->cloneWith(['checklist' => null]);
				}
			}

			return $taskData;
		}

		$checklist = $this->checkListProvider->getByEntityWithoutUserState(
			entityId: (int)$template->id,
			type: Entity\CheckList\Type::Template,
		);
		$items = [];
		/** @var Entity\CheckList\CheckListItem $item */
		foreach ($checklist as $item)
		{
			$items[] = [
				'nodeId' => $item->nodeId,
				'title' => $item->title,
				'parentId' => 0,
				'parentNodeId' => $item->parentNodeId,
				'sortIndex' => $item->sortIndex,
				'isComplete' => $item->isComplete,
				'isImportant' => $item->isImportant,
				'accomplices' => $item->accomplices?->toArray() ?? [],
				'auditors' => $item->auditors?->toArray() ?? [],
				'attachments' => $item->attachments?->toArray() ?? [],
				'copiedId' => $item->id,
			];
		}

		return $taskData->cloneWith(['checklist' => $items]);
	}

	private function isChecklistIdSnapshot(?array $checklist): bool
	{
		if (empty($checklist))
		{
			return false;
		}

		foreach ($checklist as $item)
		{
			if (!is_int($item) && !(is_string($item) && preg_match('/^[0-9]+$/D', $item) === 1))
			{
				return false;
			}
		}

		return true;
	}

	private function ensureCanSaveForEveryResponsible(Entity\Task $taskData, int $userId): void
	{
		$tasks = [$taskData];
		$primaryResponsibleId = (int)$taskData->responsible?->id;
		$responsibleIds = $taskData->multiResponsibles?->getIdList() ?? [];
		$checkedResponsibleIds = [$primaryResponsibleId => true];

		$leanTask = $taskData;
		if ($responsibleIds !== [])
		{
			// Dropping the collection once keeps the walk linear: cloneWith() serializes it every time.
			$leanTask = $taskData->cloneWith(['multiResponsibles' => null]);
		}

		foreach ($responsibleIds as $responsibleId)
		{
			$responsibleId = (int)$responsibleId;
			if ($responsibleId <= 0 || isset($checkedResponsibleIds[$responsibleId]))
			{
				continue;
			}

			$checkedResponsibleIds[$responsibleId] = true;
			$tasks[] = $leanTask->cloneWith(['responsible' => new Entity\User(id: $responsibleId)]);
		}

		foreach ($tasks as $task)
		{
			if ($this->taskAccessService->canSave($userId, $task))
			{
				continue;
			}

			$message =
				$this->taskAccessService->getUserError()?->getMessage()
				?? Loc::getMessage('TASKS_CREATE_TASK_FROM_TEMPLATE_ACCESS_DENIED')
			;

			throw new TaskAddException($message);
		}
	}

	private function prepareAttachments(Entity\Task $taskData, AddTaskConfig $config): Entity\Task
	{
		[$fileIds, $description] = $this->copyFileService->copyAttachments(
			description:     $taskData->description ?? '',
			userId:          $config->userId,
			fileIds:         $taskData->fileIds ?? [],
		);

		return $taskData->cloneWith([
			'fileIds' => $fileIds,
			'description' => $description,
		]);
	}
}
