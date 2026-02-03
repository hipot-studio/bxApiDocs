<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: false, accomplices: false, auditors: false)]
class NotifyChatCreatedForExistingTask extends AbstractNotify
{
	public function __construct(
		private readonly Task $task,
		MessageSenderInterface $sender,
	)
	{
		parent::__construct();
		$sender->sendMessage(task: $this->task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return 'TASKS_IM_TASK_CHAT_CREATED_FOR_EXISTING_TASK';
	}

	public function getMessageData(): array
	{
		return ['#TITLE#' => $this->task->title];
	}

	public function getDisableNotify(): bool
	{
		return true;
	}

	public function shouldDisableAddRecent(): bool
	{
		return true;
	}
}
