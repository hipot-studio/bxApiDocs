<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

class NotifyAuditorsChanged
{
	public function __construct(
		Entity\Task $task,
		MessageSenderInterface $sender,
		protected ?Entity\User $triggeredBy = null,
		?Entity\UserCollection $oldAuditors = null,
		?Entity\UserCollection $newAuditors = null,
		?Entity\UserCollection $newAddMembers = null,
	)
	{
		$auditorsToAdd = $newAuditors->diff($oldAuditors);

		Container::getInstance()->getLogger()
			->logWarning(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'TASKS_AUDITORS_DEBUG');

		if (!$auditorsToAdd->isEmpty())
		{
			if ($auditorsToAdd->count() === 1 && $auditorsToAdd->getFirstEntity()?->getId() === $triggeredBy?->id)
			{
				$notification = new NotifyAuditorsAssignedSelf($triggeredBy, $auditorsToAdd);
			}
			else
			{
				$notification = new NotifyAuditorsAssigned($triggeredBy, $auditorsToAdd, $newAddMembers);
			}

			$sender->sendMessage(task: $task, notification: $notification);
		}

		$auditorsToDelete = $oldAuditors->diff($newAuditors);

		if (!$auditorsToDelete->isEmpty())
		{
			if ($auditorsToDelete->count() === 1 && $auditorsToDelete->getFirstEntity()?->getId() === $triggeredBy?->id)
			{
				$notification = new NotifyAuditorsRemovedSelf($triggeredBy, $auditorsToDelete);
			}
			else
			{
				$notification = new NotifyAuditorsRemoved($triggeredBy, $auditorsToDelete);
			}

			$sender->sendMessage(task: $task, notification: $notification);
		}
	}
}
