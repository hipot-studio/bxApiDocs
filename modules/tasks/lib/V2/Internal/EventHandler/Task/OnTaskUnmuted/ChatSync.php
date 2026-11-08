<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnTaskUnmuted;

use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskUnmutedEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;

class ChatSync
{
	public function __construct(
		private readonly ChatRepositoryInterface $repository,
		private readonly Chat $chat,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnTaskUnmutedEvent $event): void
	{
		$chat = $this->repository->getByTaskId($event->task->id);

		$chatId = $chat?->getId();

		if ($chatId === null)
		{
			return;
		}

		try
		{
			if (!$this->chat->muteChat($chatId, $event->user->id, false))
			{
				$this->logger->logWarning([
					'message' => 'Could not sync task chat unmute',
					'taskId' => $event->task->id,
					'chatId' => $chatId,
					'userId' => $event->user->id,
				]);
			}
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}
