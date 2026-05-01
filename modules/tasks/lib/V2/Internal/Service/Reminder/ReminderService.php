<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Reminder;

use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder;
use Bitrix\Tasks\V2\Internal\Repository\ReminderRepositoryInterface;

class ReminderService
{
	public function __construct(
		private readonly ReminderRepositoryInterface $reminderRepository,
	)
	{

	}
	public function update(Reminder $reminder): Reminder
	{
		$newReminder = new Reminder(
			id: $reminder->getId(),
			nextRemindTs: $reminder->nextRemindTs,
			remindBy: $reminder->remindBy,
			remindVia: $reminder->remindVia,
			recipient: $reminder->recipient,
			rrule: $reminder->rrule,
			before: $reminder->before,
		);

		$this->reminderRepository->save($newReminder);

		return $newReminder;
	}
}
