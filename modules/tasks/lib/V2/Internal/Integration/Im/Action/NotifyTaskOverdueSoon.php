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
		private readonly ChatActionLinkService $chatActionLinkService,
	)
	{
		parent::__construct();
		$this->deadlineFormatter = ServiceLocator::getInstance()->get(DeadlineFormatter::class);

		$sender->sendMessage(task: $task, notification: $this);
	}

	public function getMessageCode(): string
	{
		return 'TASKS_IM_TASK_OVERDUE_SOON_MSGVER_1';
	}

	public function getMessageData(): array
	{
		$changeDeadlineLink = $this->chatActionLinkService->get(
			task: $this->task,
			userId: (int)$this->task->responsible?->id,
			action: ChatAction::ChangeDeadline,
		);

		return [
			'#RESPONSIBLE#' => $this->formatUser($this->task->responsible),
			'#DEADLINE#' => $this->deadlineFormatter->format($this->task->deadlineTs),
			'#CHANGE_DEADLINE_URL#' => $changeDeadlineLink,
		];
	}
}
