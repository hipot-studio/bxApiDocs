<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: true)]
class NotifyChecklistAuditorAssigned extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly string $checklistName = '',
		private readonly ?Entity\User $assignee = null,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_CHECKLIST_OBSERVER_ASSIGNED_M',
			Entity\User\Gender::Female => 'TASKS_IM_CHECKLIST_OBSERVER_ASSIGNED_F',
			default                    => 'TASKS_IM_CHECKLIST_OBSERVER_ASSIGNED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#ASSIGNEE#' => $this->formatUser($this->assignee),
			'#CHECKLIST_NAME#' => $this->checklistName,
		];
	}
}
