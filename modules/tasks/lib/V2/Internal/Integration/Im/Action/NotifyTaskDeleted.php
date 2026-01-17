<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyTaskDeleted extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
	)
	{
	}

	public function getMessageCode(): string
	{
		return match($this->triggeredBy?->getGender()) {
			Entity\User\Gender::Male   => 'TASKS_IM_TASK_DELETED_M',
			Entity\User\Gender::Female => 'TASKS_IM_TASK_DELETED_F',
			default                    => 'TASKS_IM_TASK_DELETED_M',
		};
	}

	public function getMessageData(): array
	{
		return [];
	}
}
