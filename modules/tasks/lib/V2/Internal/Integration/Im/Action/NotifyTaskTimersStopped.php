<?php

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyTaskTimersStopped extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		private readonly MessageSenderInterface $sender,
		private readonly ChatActionLinkService $chatActionLinkService,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?int $seconds = null,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return 'TASKS_IM_TASK_ELAPSED_TIME_ALL_STOPPED_MSGVER_1';
	}

	public function getMessageData(): array
	{
		$actionLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->triggeredBy?->getId(),
			action: ChatAction::OpenTimeTracking,
		);

		return [
			'#ACTION_LINK#' => $actionLink,
			'#TIME#' => $this->formatElapsedTime((int)$this->seconds),
		];
	}
}
