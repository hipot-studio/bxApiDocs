<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyCrmItemsChanged extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
	)
	{
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Female => 'TASKS_IM_TASK_CRM_ITEMS_CHANGED_F',
			default => 'TASKS_IM_TASK_CRM_ITEMS_CHANGED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#TITLE#' => $this->task->title,
		];
	}
}
