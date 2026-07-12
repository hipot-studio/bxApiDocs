<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyTaskTimerStarted extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		private readonly MessageSenderInterface $sender,
		private readonly ChatActionLinkService $chatActionLinkService,
		protected readonly ?Entity\User $triggeredBy = null,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return match($this->triggeredBy?->getGender())
		{
			Entity\User\Gender::Female => 'TASKS_IM_TASK_ELAPSED_TIME_STARTED_F_MSGVER_1',
			default                    => 'TASKS_IM_TASK_ELAPSED_TIME_STARTED_M_MSGVER_1',
		};
	}

	public function getMessageData(): array
	{
		$actionLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->getId(),
			action: ChatAction::OpenTimeTracking,
		);

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#ACTION_LINK#' => $actionLink,
		];
	}
}
