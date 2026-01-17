<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Tasks\V2\Internal\Access\Service\ReminderAccessService;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder;
use Bitrix\Tasks\V2\Internal\Exception\Task\ReminderException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\AddReminderDto;

class ReminderService
{
	public function __construct(
		private readonly ReminderAccessService $accessService,
		private readonly \Bitrix\Tasks\V2\Internal\Service\Task\ReminderService $reminderService,
	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws InvalidIdentifierException
	 * @throws ReminderException
	 */
	public function add(AddReminderDto $dto, int $userId): void
	{
		if ($dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		if (!$this->accessService->canAdd($userId, $dto->taskId))
		{
			throw new AccessDeniedException();
		}

		$reminder = new Reminder(
			userId: $dto->userId,
			taskId: $dto->taskId,
			nextRemindTs: $dto->nextRemindTs,
			remindBy: $dto->remindBy,
			remindVia: $dto->remindVia,
			recipient: $dto->recipient,
		);

		$this->reminderService->add($reminder);
	}
}
