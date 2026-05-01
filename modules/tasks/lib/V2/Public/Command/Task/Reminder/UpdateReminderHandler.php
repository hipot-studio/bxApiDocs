<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Reminder;

use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder;
use Bitrix\Tasks\V2\Internal\Service\Reminder\ReminderService;

class UpdateReminderHandler
{
	public function __construct(
		private readonly ReminderService $reminderService,
	)
	{

	}

	public function __invoke(UpdateReminderCommand $command): Reminder
	{
		return $this->reminderService->update($command->reminder);
	}
}
