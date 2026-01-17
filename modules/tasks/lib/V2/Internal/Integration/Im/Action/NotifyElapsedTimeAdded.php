<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

class NotifyElapsedTimeAdded extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?Entity\Task\ElapsedTime $elapsedTime,
	)
	{
	}

	public function getMessageCode(): string
	{
		if (!empty($this->elapsedTime->text))
		{
			return match ($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_ELAPSED_TIME_ADDED_WITH_TEXT_F',
				default => 'TASKS_IM_TASK_ELAPSED_TIME_ADDED_WITH_TEXT_M',
			};
		}

		return match ($this->triggeredBy?->getGender())
		{
			Entity\User\Gender::Female => 'TASKS_IM_TASK_ELAPSED_TIME_ADDED_F',
			default => 'TASKS_IM_TASK_ELAPSED_TIME_ADDED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#TIME#' => $this->formatElapsedTime($this->elapsedTime->seconds),
			'#TEXT#' => $this->elapsedTime->text,
		];
	}
}
