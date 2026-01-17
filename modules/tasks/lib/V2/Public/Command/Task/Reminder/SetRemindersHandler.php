<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Reminder;

use Bitrix\Tasks\V2\Internal\Repository\ReminderRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\ReminderService;

class SetRemindersHandler
{
	public function __construct(
		private readonly ReminderService $reminderService,
		private readonly ReminderRepositoryInterface $reminderRepository,
		private readonly ConsistencyResolverInterface $consistencyResolver,
	)
	{

	}

	public function __invoke(SetRemindersCommand $command): void
	{
		if ($command->useConsistency)
		{
			$this->consistencyResolver->resolve('task.reminder')->wrap(
				fn() => $this->updateReminders($command),
			);
		}
		else
		{
			$this->updateReminders($command);
		}
	}

	private function updateReminders(SetRemindersCommand $command): void
	{
		$this->reminderRepository->deleteByFilter(['=USER_ID' => $command->userId, '=TASK_ID' => $command->taskId]);

		if ($command->reminders->isEmpty())
		{
			return;
		}

		$reminders = $command->reminders->cloneWith(['userId' => $command->userId, 'taskId' => $command->taskId]);

		$this->reminderService->addMulti($reminders);
	}
}
