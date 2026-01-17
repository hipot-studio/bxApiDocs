<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\Deadline\DeadlineFormatter;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

#[Recipients(creator: true, responsible: true, accomplices: true, auditors: false)]
class NotifyDeadlineChanged extends AbstractNotify
{
	private readonly DeadlineFormatter $deadlineFormatter;

	public function __construct(
		private readonly Entity\Task $task,
		MessageSenderInterface $sender,
		protected readonly ?Entity\User $triggeredBy = null,
		private readonly ?int $newDeadlineTs = null,
		private readonly ?int $oldDeadlineTs = null,
	)
	{
		$this->deadlineFormatter = ServiceLocator::getInstance()->get(DeadlineFormatter::class);

		if ($oldDeadlineTs !== null && $newDeadlineTs !== null)
		{
			$sender->sendMessage(task: $this->task, notification: $this);
		}
		elseif ($oldDeadlineTs !== null && $newDeadlineTs === null)
		{
			$notification = new NotifyDeadlineRemoved($this->task, $this->triggeredBy);
			$sender->sendMessage(task: $this->task, notification: $notification);
		}
		elseif ($newDeadlineTs !== null)
		{
			$notification = new NotifyDeadlineAdded($this->deadlineFormatter, $this->task, $this->triggeredBy, $this->newDeadlineTs);
			$sender->sendMessage(task: $this->task, notification: $notification);
		}
	}

	public function getMessageCode(): string
	{
		$messageKey = $this->isReasonRequired() ? '_REASON' : '';

		return match ($this->triggeredBy?->getGender())
		{
			Entity\User\Gender::Female => 'TASKS_IM_TASK_DEADLINE_CHANGED' . $messageKey . '_F_MSGVER_1',
			default => 'TASKS_IM_TASK_DEADLINE_CHANGED' . $messageKey . '_M_MSGVER_1',
		};
	}

	public function getMessageData(): array
	{
		$newDeadline = $this->deadlineFormatter->format($this->newDeadlineTs);

		$reason = $this->isReasonRequired() ? $this->task->deadlineChangeReason : '';

		return [
			'#USER#' => $this->formatUser($this->triggeredBy),
			'#NEW_DEADLINE#' => $newDeadline,
			'#REASON#' => $reason,
		];
	}

	private function isReasonRequired(): bool
	{
		$userId = (int)$this->triggeredBy?->id;

		$isCreator = $this->task->creator?->getId() === $userId;

		$user = UserModel::createFromId($userId);
		$canEdit = TaskAccessController::can($userId, ActionDictionary::ACTION_TASK_EDIT, $this->task->getId());

		$canChangeDeadline = ($isCreator || $user->isAdmin() || $canEdit);

		return !$canChangeDeadline && $this->task->requireDeadlineChangeReason;
	}
}
