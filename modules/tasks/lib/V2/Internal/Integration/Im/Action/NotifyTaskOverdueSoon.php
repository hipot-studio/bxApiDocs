<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\Deadline\DeadlineFormatter;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: false, responsible: true, accomplices: true, auditors: false)]
class NotifyTaskOverdueSoon extends AbstractNotify
{
	private readonly DeadlineFormatter $deadlineFormatter;

	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
	)
	{
		parent::__construct();
		$this->deadlineFormatter = ServiceLocator::getInstance()->get(DeadlineFormatter::class);

		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return 'TASKS_IM_TASK_OVERDUE_SOON';
	}

	public function getMessageData(): array
	{
		return [
			'#RESPONSIBLE#' => $this->formatUser($this->task->responsible),
			'#DEADLINE#' => $this->deadlineFormatter->format($this->task->deadlineTs),
		];
	}

	public function toString(): string
	{
		$message = parent::toString();

		return $this->stripBbCodeUrl($message);
	}
}
