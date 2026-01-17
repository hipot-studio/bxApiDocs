<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyPriorityChanged extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly ?Entity\Priority $priority,
	) {
	}

	public function getMessageCode(): string
	{
		return match ($this->priority)
		{
			Entity\Priority::High => match ($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Male   => 'TASKS_IM_TASK_PRIORITY_SET_M',
				Entity\User\Gender::Female => 'TASKS_IM_TASK_PRIORITY_SET_F',
				default                    => 'TASKS_IM_TASK_PRIORITY_SET',
			},
			default => match ($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Male   => 'TASKS_IM_TASK_PRIORITY_UNSET_M',
				Entity\User\Gender::Female => 'TASKS_IM_TASK_PRIORITY_UNSET_F',
				default                    => 'TASKS_IM_TASK_PRIORITY_UNSET',
			},
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
		];
	}
}
