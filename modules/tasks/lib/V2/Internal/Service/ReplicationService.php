<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\Control\Exception\TemplateUpdateException;
use Bitrix\Tasks\Control\Exception\WrongTaskIdException;
use Bitrix\Tasks\Integration\Pull\PushCommand;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateAccessService;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Add\TemplateFromTaskCreator;
use InvalidArgumentException;

class ReplicationService
{
	public function __construct(
		private readonly TemplateRepositoryInterface $templateRepository,
		private readonly TemplateAccessService $templateAccessService,
		private readonly TaskAccessService $taskAccessService,
		private readonly UpdateTemplateService $updateTemplateService,
		private readonly UpdateTaskService $updateTaskService,
		private readonly TemplateFromTaskCreator $templateFromTaskCreator,
		private readonly PushService $pushService,
	)
	{
	}

	/**
	 * @throws CommandValidationException
	 * @throws TaskNotExistsException
	 * @throws TaskUpdateException
	 * @throws TemplateAddException
	 * @throws WrongTaskIdException
	 */
	public function createTemplate(Entity\Task $task, int $userId): int
	{
		if ($task->replicateParams === null)
		{
			throw new TemplateAddException('ReplicationService: property replicateParams is not passed');
		}

		if ($this->templateRepository->getByTaskId((int)$task->getId()) !== null)
		{
			throw new TemplateAddException('ReplicationService: template already exists for this task');
		}

		$config = new AddConfig(userId: $userId, withReplication: true);
		$template = $this->templateFromTaskCreator->create($task, $config);

		$this->updateTaskReplication($task, $userId, true);

		$templateId = (int)$template->getId();

		$this->sendPush($userId, $templateId);

		return $templateId;
	}

	/**
	 * @throws CommandValidationException
	 * @throws TaskNotExistsException
	 * @throws TaskUpdateException
	 * @throws TemplateNotFoundException
	 * @throws TemplateUpdateException
	 * @throws WrongTaskIdException
	 */
	public function setReplicationStateByTask(Entity\Task $task, int $userId): void
	{
		if ($task->replicate === null)
		{
			throw new InvalidArgumentException('ReplicationService: property replicate is not passed');
		}

		$forkedTemplateId = $task->forkedByTemplate?->getId();

		$template =
			$forkedTemplateId !== null
				? $this->templateRepository->getById($forkedTemplateId)
				: $this->templateRepository->getByTaskId((int)$task->getId())
		;

		if ($template === null)
		{
			throw new TemplateNotFoundException();
		}

		$this->setReplicationState($template, $userId, $task->replicate);
	}

	/**
	 * @throws CommandValidationException
	 * @throws TaskNotExistsException
	 * @throws TaskUpdateException
	 * @throws TemplateUpdateException
	 * @throws WrongTaskIdException
	 */
	public function setReplicationStateByTemplate(Entity\Template $template, int $userId): void
	{
		if ($template->replicate === null)
		{
			throw new InvalidArgumentException('ReplicationService: property replicate is not passed');
		}

		$task = $this->templateRepository->getTaskByTemplateId((int)$template->getId());
		if ($task !== null)
		{
			$template = $template->cloneWith(['task' => $task->toArray()]);
		}

		$this->setReplicationState($template, $userId, $template->replicate);
	}

	private function sendPush(int $userId, int $templateId): void
	{
		$recipients = UserCollection::mapFromIds([$userId]);

		$this->pushService->addEvent($recipients, [
			'module_id' => $this->pushService->getModuleName(),
			'command' => PushCommand::TASK_REGULAR_TEMPLATE_ADDED,
			'params' => [
				'templateId' => $templateId,
			],
		]);
	}

	/**
	 * @throws TaskNotExistsException
	 * @throws CommandValidationException
	 * @throws WrongTaskIdException
	 * @throws TemplateUpdateException
	 * @throws TaskUpdateException
	 */
	private function setReplicationState(Entity\Template $template, int $userId, bool $replicate): void
	{
		$this->updateTemplateReplication($template, $userId, $replicate);

		if ($template->task !== null)
		{
			$this->updateTaskReplication($template->task, $userId, $replicate);
		}
	}

	/**
	 * @throws TaskNotExistsException
	 * @throws CommandValidationException
	 * @throws WrongTaskIdException
	 * @throws TaskUpdateException
	 */
	private function updateTaskReplication(Entity\Task $task, int $userId, bool $replicate): void
	{
		$taskToUpdate = new Entity\Task(
			id: $task->getId(),
			replicate: $replicate,
		);

		if (!$this->taskAccessService->canSave($userId, $taskToUpdate))
		{
			throw new TaskUpdateException('ReplicationService: no permissions to update task');
		}

		$this->updateTaskService->update(
			task: $taskToUpdate,
			config: new Task\Action\Update\Config\UpdateConfig(userId: $userId),
		);
	}

	/**
	 * @throws TemplateUpdateException
	 */
	private function updateTemplateReplication(Entity\Template $template, int $userId, bool $replicate): void
	{
		$templateToUpdate = new Entity\Template(
			id: (int)$template->getId(),
			replicate: $replicate,
		);

		if (!$this->templateAccessService->canSave($userId, $templateToUpdate))
		{
			throw new TemplateUpdateException('ReplicationService: no permissions to update template');
		}

		$this->updateTemplateService->update(
			template: $templateToUpdate,
			config: new Template\Action\Update\Config\UpdateConfig(userId: $userId),
		);
	}
}
