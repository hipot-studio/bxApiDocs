<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Reminder;

use Bitrix\Tasks\V2\Internal\Repository\ReminderReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\ReminderService;

class CopyReminderService
{
	public function __construct(
		private readonly ReminderService $reminderService,
		private readonly ReminderReadRepositoryInterface $reminderReadRepository,
	)
	{

	}

	public function copy(int $fromTaskId, int $toTaskId, int $userId): void
	{
		$reminders = $this->reminderReadRepository->getByTaskId($fromTaskId);

		foreach ($reminders as $reminder)
		{
			if ($reminder->nextRemindTs === null)
			{
				continue;
			}

			$newReminder = $reminder->cloneWith(['id' => null, 'userId' => $userId, 'taskId' => $toTaskId]);

			$this->reminderService->add($newReminder);
		}
	}
}
