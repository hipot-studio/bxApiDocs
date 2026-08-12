<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service;

use Bitrix\Im\V2\Integration\Tasks\Access\TaskAccessService;
use Bitrix\Im\V2\Integration\Tasks\Exception\AccessDeniedException;
use Bitrix\Im\V2\Integration\Tasks\Exception\AddTaskException;
use Bitrix\Im\V2\Integration\Tasks\Exception\DeleteTaskException;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Config\DeleteConfig;
use Bitrix\Tasks\V2\Public\Command\Task\AddTaskCommand;
use Bitrix\Tasks\V2\Public\Command\Task\DeleteTaskCommand;

class TaskService
{
	public function __construct(
		private readonly TaskAccessService $accessService,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws AddTaskException
	 */
	public function add(Task $task, int $userId): Task
	{
		if (!$this->accessService->canSave($userId, $task))
		{
			throw new AccessDeniedException();
		}

		$config = new AddConfig($userId);

		/** @var Result $commandResult */
		$commandResult = (new AddTaskCommand($task, $config))->run();

		if (!$commandResult->isSuccess())
		{
			$error = $commandResult->getError();

			throw new AddTaskException($error->getMessage(), $error->getCode());
		}

		/** @var Task $addedTask */
		$addedTask = $commandResult->getObject();

		return $addedTask;
	}

	/**
	 * @throws AccessDeniedException
	 * @throws CommandException
	 * @throws CommandValidationException
	 * @throws DeleteTaskException
	 */
	public function delete(int $taskId, int $userId): void
	{
		if (!$this->accessService->canDelete($userId, $taskId))
		{
			throw new AccessDeniedException();
		}

		$config = new DeleteConfig($userId);

		/** @var Result $commandResult */
		$commandResult = (new DeleteTaskCommand($taskId, $config))->run();

		if (!$commandResult->isSuccess())
		{
			$error = $commandResult->getError();

			throw new DeleteTaskException($error->getMessage(), $error->getCode());
		}
	}
}
