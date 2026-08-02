<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Template\Convert;

use Bitrix\Tasks\Control\Exception\TaskNotFoundException;
use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Add\TemplateFromTaskCreator;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;
use Bitrix\Tasks\V2\Public\Provider\TaskProvider;

class ConvertToTemplateByTaskIdHandler
{
	public function __construct(
		private readonly TaskProvider $taskProvider,
		private readonly TemplateFromTaskCreator $templateFromTaskCreator,
	)
	{
	}

	/**
	 * @throws TaskNotFoundException
	 * @throws TemplateAddException
	 */
	public function __invoke(ConvertToTemplateByTaskIdCommand $command): Entity\Template
	{
		$task = $this->getTask($command->taskId, $command->userId);

		$config = new AddConfig(
			userId: $command->userId,
			withReplication: $task->replicate ?? false,
		);

		return $this->templateFromTaskCreator->create($task, $config);
	}

	/**
	 * @throws TaskNotFoundException
	 */
	private function getTask(int $taskId, int $userId): Entity\Task
	{
		$params = $this->getTaskParams($taskId, $userId);

		$task = $this->taskProvider->get($params);

		if ($task === null)
		{
			throw new TaskNotFoundException('Task was not found');
		}

		return $task;
	}

	private function getTaskParams(int $taskId, int $userId): TaskParams
	{
		return new TaskParams(
			taskId: $taskId,
			userId: $userId,
			group: true,
			flow: false,
			stage: false,
			members: true,
			checkLists: false,
			tags: true,
			crm: true,
			email: false,
			subTasks: false,
			relatedTasks: true,
			gantt: false,
			placements: false,
			containsCommentFiles: false,
			favorite: false,
			options: true,
			parameters: true,
			results: false,
			reminders: false,
			userFields: true,
			checkTaskAccess: true,
			checkGroupAccess: true,
			checkFlowAccess: false,
			checkCrmAccess: true,
			checkParentAccess: true,
			view: false,
			scenarios: true,
		);
	}
}
