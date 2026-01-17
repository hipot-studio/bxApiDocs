<?php

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyTaskMovedToBacklog extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Task $task,
		protected readonly ?User $triggeredBy = null,
	)
	{
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender())
		{
			User\Gender::Male => 'TASKS_IM_TASK_STAGE_CHANGED_BACKLOG_M',
			User\Gender::Female => 'TASKS_IM_TASK_STAGE_CHANGED_BACKLOG_F',
			default => 'TASKS_IM_TASK_STAGE_CHANGED_BACKLOG',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
		];
	}
}
