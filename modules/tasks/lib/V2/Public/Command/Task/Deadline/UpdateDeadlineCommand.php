<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Deadline;

use Bitrix\Main\Error;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Deadline\Policy\DeadlinePolicy;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Exception;

class UpdateDeadlineCommand extends AbstractCommand
{
	public function __construct(
		#[PositiveNumber]
		public readonly int $taskId,
		#[Min(0)]
		public readonly int $deadlineTs,
		public readonly UpdateConfig $updateConfig,
		public readonly ?string $reason = null,
	)
	{

	}

	protected function executeInternal(): Result
	{
		$result = new Result();

		$taskRepository = Container::getInstance()->getTaskReadRepository();
		$task = $taskRepository->getById(
			id: $this->taskId,
			select: new Select(
				members: true,
				parameters: true,
			),
		);
		if (!$task)
		{
			return $result;
		}

		$userId = $this->updateConfig->getUserId();
		$isCreator = $task->creator->getId() === $userId;
		$user = UserModel::createFromId($userId);
		$canEdit = TaskAccessController::can($userId, ActionDictionary::ACTION_TASK_EDIT, $task->getId());

		$canChangeDeadline = ($isCreator || $user->isAdmin() || $canEdit);

		$updateService = Container::getInstance()->getUpdateTaskService();

		$deadLineLogRepository = Container::getInstance()->getDeadlineLogRepository();
		$deadLinePolicy = new DeadlinePolicy(
			canChangeDeadline: $canChangeDeadline,
			dateTime: $task->maxDeadlineChangeDate,
			maxDeadlineChanges: $task->maxDeadlineChanges,
			requireDeadlineChangeReason: $task->requireDeadlineChangeReason,
		);

		$updateDeadlineHandler = new UpdateDeadlineHandler(
			$updateService,
			$deadLinePolicy,
			$deadLineLogRepository,
		);

		try
		{
			$task = $updateDeadlineHandler($this);

			return $result->setObject($task);
		}
		catch (Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
