<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\Stage;
use Bitrix\Tasks\V2\Internal\Entity\User\Gender;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyTaskStageChanged extends AbstractNotify
{
	public function __construct(
		private readonly Task $task,
		MessageSenderInterface $sender,
		protected readonly ?User $triggeredBy = null,
		private readonly ?Stage $newStage = null,
	)
	{
		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return match($this->triggeredBy?->getGender())
		{
			Gender::Female => 'TASKS_IM_TASK_STAGE_CHANGED_F',
			default => 'TASKS_IM_TASK_STAGE_CHANGED_M',
		};
	}

	public function getMessageData(): array
	{
		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#NEW_STAGE#' => $this->newStage->title,
		];
	}
}
