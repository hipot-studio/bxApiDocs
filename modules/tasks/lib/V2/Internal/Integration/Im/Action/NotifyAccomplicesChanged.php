<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

class NotifyAccomplicesChanged
{
	public function __construct(
		Entity\Task $task,
		MessageSenderInterface $sender,
		protected ?Entity\User $triggeredBy = null,
		?Entity\UserCollection $oldAccomplices = null,
		?Entity\UserCollection $newAccomplices = null,
		?Entity\UserCollection $newAddMembers = null,
	)
	{
		$newDiff = $newAccomplices?->diff($oldAccomplices ?? new Entity\UserCollection());
		$oldDiff = $oldAccomplices?->diff($newAccomplices ?? new Entity\UserCollection());

		if (!$newDiff->isEmpty())
		{
			$notification = new NotifyAccomplicesAssigned($triggeredBy, $newDiff, $newAddMembers);
			$sender->sendMessage($task, $notification);
		}

		if (!$oldDiff->isEmpty())
		{
			$notification = new NotifyAccomplicesRemoved($triggeredBy, $oldDiff);
			$sender->sendMessage($task, $notification);
		}
	}
}
