<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Main\Localization\Loc;

class ChatNotification implements ChatNotificationInterface, MessageSenderInterface
{
	/**
	 * Generic notification method.
	 * @param NotificationType $type
	 * @param Task $task
	 * @param array $args Additional arguments for replacements (e.g., triggeredBy, oldResponsible, etc.)
	 */
	public function notify(NotificationType $type, Task $task, array $args = []): void
	{
		$this->loadMessages();

		match ($type) {
			NotificationType::ChatCreatedForExistingTask => new Action\NotifyChatCreatedForExistingTask($task, $this, $args),
			NotificationType::TaskHasForumComments => new Action\NotifyTaskHasForumComments($task, $this, $args),
			NotificationType::TaskHasLegacyChat => new Action\NotifyTaskHasLegacyChat($task, $this, $args),
			NotificationType::TaskCreated => new Action\NotifyTaskCreated($task, $this, $args),
			NotificationType::ResponsibleChanged => new Action\NotifyResponsibleChanged($task, $this, $args),
			NotificationType::OwnerChanged => new Action\NotifyOwnerChanged($task, $this, $args),
			NotificationType::DeadlineChanged => new Action\NotifyDeadlineChanged($task, $this, $args),
			NotificationType::AuditorsChanged => new Action\NotifyAuditorsChanged($task, $this, $args),
			NotificationType::AccomplicesChanged => new Action\NotifyAccomplicesChanged($task, $this, $args),
			NotificationType::GroupChanged => new Action\NotifyGroupChanged($task, $this, $args),
			NotificationType::TaskOverdue => new Action\NotifyTaskOverdue($task, $this, $args),
			NotificationType::TaskStatusChanged => new Action\NotifyTaskStatusChanged($task, $this, $args),
			NotificationType::TaskTimerStarted => new Action\NotifyTaskTimerStarted($task, $this, $args),
			NotificationType::TaskTimerStopped => new Action\NotifyTaskTimerStopped($task, $this, $args),
			default => null,
		};

	}

	public function sendMessage(Task $task, string|null $text): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if ($text === null)
		{
			return;
		}

		if ($task->chatId === null)
		{
			return;
		}

		$authorId = 0; // system user

		$chat = \Bitrix\Im\V2\Chat::getInstance($task->chatId);
		$message = (new \Bitrix\Im\V2\Message())
			->setMessage($text)
			->setAuthorId($authorId)
		;
		$chat->sendMessage($message);
	}

	private function loadMessages(): void
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/V2/Internal/Integration/Im/ChatNotification.php');
	}
}
