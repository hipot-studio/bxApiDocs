<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Ping;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;

class ChatMessagePing implements PingActionInterface
{
	public function execute(int $taskId, int $userId, array $taskData): void
	{
		$task = Container::getInstance()->getTaskRepository()->getById($taskId);

		if ($task->creator->id === $userId)
		{
			$triggeredBy = $task->creator;
		}
		elseif ($task->responsible->id === $userId)
		{
			$triggeredBy = $task->responsible;
		}
		else
		{
			$fnComparator = fn(User $user): bool => $user->id === $userId;
			$triggeredBy = $task->accomplices->find($fnComparator) ?? $task->auditors->find($fnComparator);
		}

		Container::getInstance()->get(ChatNotificationInterface::class)->notify(
			type: NotificationType::TaskStatusPinged,
			task: $task,
			args: ['triggeredBy' => $triggeredBy],
		);
	}
}
