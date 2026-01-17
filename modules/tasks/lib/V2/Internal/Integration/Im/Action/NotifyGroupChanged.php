<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyGroupChanged extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly ?Entity\Group $newGroup = null,
		private readonly ?Entity\Group $oldGroup = null,
	)
	{
		if ($oldGroup !== null && $newGroup !== null)
		{
			$sender->sendMessage(task: $task, notification: $this);
		}
		elseif ($oldGroup !== null && $newGroup === null)
		{
			$notification = new NotifyGroupRemoved($this->triggeredBy, $this->oldGroup);
			$sender->sendMessage(task: $task, notification: $notification);
		}
		elseif ($newGroup !== null)
		{
			$notification = new NotifyGroupAdded($this->triggeredBy, $this->newGroup);
			$sender->sendMessage(task: $task, notification: $notification);
		}
	}

	public function getMessageCode(): string
	{
		$secretCode = $this->oldGroup->isVisible && $this->newGroup->isVisible ? '' : 'SECRET_';

		return match($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male => "TASKS_IM_TASK_GROUP_CHANGED_{$secretCode}M",
			Entity\User\Gender::Female => "TASKS_IM_TASK_GROUP_CHANGED_{$secretCode}F",
			default => "TASKS_IM_TASK_GROUP_CHANGED_{$secretCode}M",
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#OLD_GROUP#' => $this->oldGroup->name,
			'#NEW_GROUP#' => $this->newGroup->name,
		];
	}
}
