<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyOwnerChanged extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly ?Entity\User $oldOwner = null,
		private readonly ?Entity\User $newOwner = null,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_TASK_OWNER_CHANGED_M',
			Entity\User\Gender::Female => 'TASKS_IM_TASK_OWNER_CHANGED_F',
			default                    => 'TASKS_IM_TASK_OWNER_CHANGED_M',
		};
	}

	public function getMessageData(): array	
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#OLD_OWNER#' => $this->formatUser($this->oldOwner),
			'#NEW_OWNER#' => $this->formatUser($this->newOwner),
		];
	}
}
