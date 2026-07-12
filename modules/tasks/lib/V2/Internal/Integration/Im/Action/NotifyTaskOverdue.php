<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\Deadline\DeadlineFormatter;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyTaskOverdue extends AbstractNotify
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
		return 'TASKS_IM_TASK_OVERDUE_MSGVER_1';
	}

	public function getMessageData(): array
	{
		$responsibleId = (int)$this->task->responsible?->id;

		$completeTaskLink = $this->chatActionLinkService->get($this->task, $responsibleId, ChatAction::CompleteTask);
		$changeDeadlineLink = $this->chatActionLinkService->get($this->task, $responsibleId, ChatAction::ChangeDeadline);

		return [
			'#RESPONSIBLE#' => $this->formatUser($this->task->responsible),
			'#DEADLINE#' => $this->deadlineFormatter->format($this->task->deadlineTs),
			'#COMPLETE_TASK_URL#' => $completeTaskLink,
			'#CHANGE_DEADLINE_URL#' => $changeDeadlineLink,
		];
	}
}
