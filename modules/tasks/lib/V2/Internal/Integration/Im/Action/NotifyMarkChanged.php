<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyMarkChanged extends AbstractNotify implements ShouldSend
{
	public function __construct(
		protected readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?Entity\Task\Mark $markBefore,
	)
	{
	}

	public function getRecipients(): array
	{
		$markAfter = $this->task->mark ?? Entity\Task\Mark::None;

		return match($markAfter)
		{
			Entity\Task\Mark::None => [],
			default => parent::getRecipients(),
		};
	}

	public function getMessageCode(): string
	{
		// Determine previous and current mark values (backed enum value or empty string)
		$before = $this->markBefore ?? Entity\Task\Mark::None;
		$current = $this->task->mark ?? Entity\Task\Mark::None;

		// Determine if the mark was unset (was set before, now empty)
		$isUnset = $before !== Entity\Task\Mark::None && $current === Entity\Task\Mark::None;

		$isPositive = !$isUnset && $current === Entity\Task\Mark::Positive;
		$isFemale = $this->triggeredBy?->getGender() === Entity\User\Gender::Female;

		return match (true) {
			$isUnset && $isFemale => 'TASKS_IM_TASK_MARK_UNSET_F',
			$isUnset && !$isFemale => 'TASKS_IM_TASK_MARK_UNSET_M',
			$isPositive && $isFemale => 'TASKS_IM_TASK_MARK_SET_POSITIVE_F',
			$isPositive && !$isFemale => 'TASKS_IM_TASK_MARK_SET_POSITIVE_M',
			!$isPositive && $isFemale => 'TASKS_IM_TASK_MARK_SET_NEGATIVE_F',
			!$isPositive && !$isFemale => 'TASKS_IM_TASK_MARK_SET_NEGATIVE_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->getTriggeredBy()),
		];
	}
}