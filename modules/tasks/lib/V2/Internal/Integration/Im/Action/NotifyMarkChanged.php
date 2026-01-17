<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyMarkChanged extends AbstractNotify
{
	public function __construct(
		protected readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly Entity\Task\Mark $markBefore,
	)
	{
	}

	public function getMessageCode(): string
	{
		// Determine previous and current mark values (backed enum value or empty string)
		$before = $this->markBefore?->value ?? '';
		$current = $this->task->mark?->value ?? '';

		// Determine if the mark was unset (was set before, now empty)
		$isUnset = $before !== '' && $current === '';

		$gender = $this->triggeredBy?->getGender();

		if ($isUnset)
		{
			return match ($gender)
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_MARK_UNSET_F',
				default => 'TASKS_IM_TASK_MARK_UNSET_M',
			};
		}

		// Otherwise consider this a mark set/change event
		return match ($gender)
		{
			Entity\User\Gender::Female => 'TASKS_IM_TASK_MARK_SET_F',
			default => 'TASKS_IM_TASK_MARK_SET_M',
		};
	}

	public function getMessageData(): array
	{
		// human-readable mark names come from Internals\Task\Mark::getMessage
		$before = $this->markBefore?->value ?? '';
		$after = $this->task->mark?->value ?? '';

		return [
			'#USER#' => $this->formatUser($this->getTriggeredBy()),
			'#MARK_BEFORE#' => InternalsMark::getMessage($before) ?? '',
			'#MARK_AFTER#' => InternalsMark::getMessage($after) ?? '',
		];
	}
}