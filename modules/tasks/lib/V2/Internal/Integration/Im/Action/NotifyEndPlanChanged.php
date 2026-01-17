<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\Deadline\DeadlineFormatter;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyEndPlanChanged extends AbstractNotify
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
			Entity\User\Gender::Female => 'TASKS_IM_TASK_PLAN_END_CHANGED_F',
			default => 'TASKS_IM_TASK_PLAN_END_CHANGED_M',
		};
	}

	public function getMessageData(): array
	{
		$dateFormatter = ServiceLocator::getInstance()->get(DeadlineFormatter::class);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#NEW_PLAN_END_DATE#' => $dateFormatter->format($this->task->endPlanTs),
		];
	}
}
