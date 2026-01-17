<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLogCollection;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepositoryInterface;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyTaskDescriptionChanged extends AbstractNotify implements ShouldSend
{
	private HistoryLogCollection $logs;

	public function __construct(
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly ?string $oldDescription = null,
		private readonly ?string $newDescription = null,
	)
	{
		$this->logs = Container::getInstance()
			->get(TaskLogRepositoryInterface::class)
			->tailWithFieldAndValues(
				taskId: $this->task->getId(),
				field: 'DESCRIPTION',
			);
	}


	public function getMessageCode(): string
	{
		if (empty($this->oldDescription) && $this->logs->count() === 1)
		{
			return $this->triggeredBy?->getGender() === Entity\User\Gender::Female
				? 'TASKS_IM_DESCRIPTION_ADDED_F'
				: 'TASKS_IM_DESCRIPTION_ADDED_M'
			;
		}

		if (empty($this->newDescription))
		{
			return $this->triggeredBy?->getGender() === Entity\User\Gender::Female
				? 'TASKS_IM_DESCRIPTION_REMOVED_F'
				: 'TASKS_IM_DESCRIPTION_REMOVED_M'
			;
		}

		return $this->triggeredBy?->getGender() === Entity\User\Gender::Female
			? 'TASKS_IM_DESCRIPTION_MODIFIED_F'
			: 'TASKS_IM_DESCRIPTION_MODIFIED_M'
		;
	}

	public function getMessageData(): array
	{
		return [
			'#TITLE#' => $this->task->title,
			'#USER#' => $this->formatUser($this->triggeredBy),
		];
	}
}
