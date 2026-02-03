<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLog;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Role;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: true)]
class NotifyTaskStatusChanged extends AbstractNotify implements ShouldSend
{
	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy,
		private readonly ?Entity\Task\Status $oldStatus = null,
		private readonly ?Entity\Task\Status $newStatus = null,
	)
	{
	}

	public function getRecipients(): array
	{
		return match($this->newStatus)
		{
			Entity\Task\Status::Completed => [Role::Creator, Role::Responsible, Role::Accomplice, Role::Auditor],
			Entity\Task\Status::InProgress,
			Entity\Task\Status::Deferred => [Role::Creator, Role::Responsible, Role::Accomplice],
			Entity\Task\Status::SupposedlyCompleted => [Role::Creator],
			Entity\Task\Status::Pending => [Role::Responsible],
			default => [],
		};
	}

	public function getMessageCode(): string
	{
		if ($this->newStatus === Entity\Task\Status::InProgress)
		{
			$logs = Container::getInstance()->get(TaskLogRepositoryInterface::class)->tailWithFieldAndValues(taskId: $this->task->getId(), field: 'STATUS', toValue: Status::IN_PROGRESS);
			$isFirstTimeInProgress = $logs->filter(fn (HistoryLog $log) => $log->field === 'STATUS' && (int)$log->toValue === Status::IN_PROGRESS)->count() === 1;

			if ($isFirstTimeInProgress)
			{
				return match($this->triggeredBy?->getGender())
				{
					Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_IN_PROGRESS_F',
					default => 'TASKS_IM_TASK_STATUS_IN_PROGRESS_M',
				};
			}

			return match($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_IN_PROGRESS_AFTER_PENDING_F',
				default => 'TASKS_IM_TASK_STATUS_IN_PROGRESS_AFTER_PENDING_M',
			};
		}

		if ($this->newStatus === Entity\Task\Status::Pending)
		{
			if ($this->oldStatus === Entity\Task\Status::Deferred)
			{
				return match($this->triggeredBy?->getGender())
				{
					Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_PENDING_AFTER_DEFER_F',
					default => 'TASKS_IM_TASK_STATUS_PENDING_AFTER_DEFER_M',
				};
			}

			if ($this->oldStatus === Entity\Task\Status::SupposedlyCompleted)
			{
				return match ($this->triggeredBy?->getGender())
				{
					Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_PENDING_AFTER_SUPPOSEDLY_COMPLETED_F',
					default => 'TASKS_IM_TASK_STATUS_PENDING_AFTER_SUPPOSEDLY_COMPLETED_M',
				};
			}

			if ($this->oldStatus === Entity\Task\Status::Completed)
			{
				return match($this->triggeredBy?->getGender())
				{
					Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_PENDING_AFTER_COMPLETE_F',
					default => 'TASKS_IM_TASK_STATUS_PENDING_AFTER_COMPLETE_M',
				};
			}
		}

		if ($this->newStatus === Entity\Task\Status::SupposedlyCompleted)
		{
			$logs = Container::getInstance()->get(TaskLogRepositoryInterface::class)->tailWithFieldAndValues(taskId: $this->task->getId(), field: 'STATUS', toValue: Status::SUPPOSEDLY_COMPLETED);
			$isFirstTime = $logs->filter(fn (HistoryLog $log) => $log->field === 'STATUS' && (int)$log->toValue === Status::SUPPOSEDLY_COMPLETED)->count() === 1;

			if ($isFirstTime)
			{
				return match ($this->triggeredBy?->getGender())
				{
					Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_SUPPOSEDLY_COMPLETED_F',
					default => 'TASKS_IM_TASK_STATUS_SUPPOSEDLY_COMPLETED_M',
				};
			}

			return match ($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_SUPPOSEDLY_COMPLETED_AFTER_RETURN_F',
				default => 'TASKS_IM_TASK_STATUS_SUPPOSEDLY_COMPLETED_AFTER_RETURN_M',
			};
		}

		if ($this->newStatus === Entity\Task\Status::Completed)
		{
			if ($this->oldStatus === Entity\Task\Status::SupposedlyCompleted)
			{
				return match ($this->triggeredBy?->getGender())
				{
					Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_COMPLETED_AFTER_SUPPOSEDLY_COMPLETED_F',
					default => 'TASKS_IM_TASK_STATUS_COMPLETED_AFTER_SUPPOSEDLY_COMPLETED_M',
				};
			}

			return match ($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_COMPLETED_F',
				default => 'TASKS_IM_TASK_STATUS_COMPLETED_M',
			};
		}

		return match($this->newStatus)
		{
			Entity\Task\Status::InProgress => match($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_IN_PROGRESS_F',
				default => 'TASKS_IM_TASK_STATUS_IN_PROGRESS_M',
			},
			Entity\Task\Status::Deferred => match($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_DEFER_F',
				default => 'TASKS_IM_TASK_STATUS_DEFER_M',
			},
			Entity\Task\Status::Pending => match($this->triggeredBy?->getGender())
			{
				Entity\User\Gender::Female => 'TASKS_IM_TASK_STATUS_PENDING_F',
				default => 'TASKS_IM_TASK_STATUS_PENDING_M',
			},
			default => null,
		};
	}

	public function getMessageData(): array
	{
		return [
			'#TITLE#' => $this->task->title,
			'#USER#' => $this->formatUser($this->triggeredBy),
		];
	}
}
