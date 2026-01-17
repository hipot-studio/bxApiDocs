<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyTaskTimerStopped extends AbstractNotify
{
	public function __construct(
		private readonly Entity\Task $task,
		private readonly MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?int $seconds = null,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return match($this->triggeredBy?->getGender())
		{
			Entity\User\Gender::Female => 'TASKS_IM_TASK_ELAPSED_TIME_STOPPED_F',
			default                    => 'TASKS_IM_TASK_ELAPSED_TIME_STOPPED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#TIME#' => $this->formatElapsedTime((int)$this->seconds),
		];
	}
}
