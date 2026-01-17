<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service;

use Bitrix\Im\V2\Integration\Tasks\Access\CheckListAccessService;
use Bitrix\Im\V2\Integration\Tasks\Exception\AccessDeniedException;
use Bitrix\Im\V2\Integration\Tasks\Exception\AddCheckListException;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Tasks\V2\Internal\Entity\CheckList;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\CheckList\SaveCheckListCommand;

class CheckListService
{
	public function __construct(
		private readonly CheckListAccessService $accessService,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws AddCheckListException
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	public function add(Task $task, CheckList $checkLists, int $userId): Task
	{
		if ($checkLists->isEmpty())
		{
			return $task;
		}

		if (!$this->accessService->canAdd($userId, (int)$task->getId()))
		{
			throw new AccessDeniedException();
		}

		$taskWithCheckList = $task->cloneWith(['checklist' => $checkLists->toArray()]);

		/** @var Result $commandResult */
		$commandResult = (new SaveCheckListCommand($taskWithCheckList, $userId))->run();

		if (!$commandResult->isSuccess())
		{
			$error = $commandResult->getError();

			throw new AddCheckListException($error->getMessage(), $error->getCode());
		}

		/** @var Task $updatedTask */
		$updatedTask = $commandResult->getObject();

		return $updatedTask;
	}
}
