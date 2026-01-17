<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Ping;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;

class ChatMessagePing implements PingActionInterface
{
	public function execute(int $taskId, int $userId, array $taskData): void
	{
		$task = Container::getInstance()->get(TaskReadRepositoryInterface::class)->getById($taskId, select: new Select(members: true));

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
