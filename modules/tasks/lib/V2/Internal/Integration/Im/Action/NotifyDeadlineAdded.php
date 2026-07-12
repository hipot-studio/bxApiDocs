<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\Deadline\DeadlineFormatter;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyDeadlineAdded extends AbstractNotify
{
	public function __construct(
		private readonly DeadlineFormatter $deadlineFormatter,
		private readonly ChatActionLinkService $chatActionLinkService,
		private readonly Entity\Task $task,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly int $deadlineTs,
	)
	{
	}

	public function getMessageCode(): string
	{
		return match ($this->triggeredBy?->getGender())
		{
			Entity\User\Gender::Female => 'TASKS_IM_TASK_DEADLINE_ADDED_F_MSGVER_2',
			Entity\User\Gender::Male   => 'TASKS_IM_TASK_DEADLINE_ADDED_M_MSGVER_2',
			default                    => 'TASKS_IM_TASK_DEADLINE_ADDED_M_MSGVER_2',
		};
	}

	public function getMessageData(): array
	{
		$changeDeadlineLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->id,
			action: ChatAction::ChangeDeadline,
		);

		$deadline = $this->deadlineFormatter->format($this->deadlineTs);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#CHANGE_DEADLINE_URL#' => $changeDeadlineLink,
			'#NEW_DEADLINE#' => $deadline,
		];
	}
}
