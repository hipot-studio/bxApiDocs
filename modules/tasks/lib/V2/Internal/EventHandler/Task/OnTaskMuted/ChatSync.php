<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnTaskMuted;

use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskMutedEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Traits\MutexTrait;

class ChatSync
{
	use MutexTrait;

	public function __construct(
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly Chat $chat,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnTaskMutedEvent $event): void
	{
		if (self::locked())
		{
			return;
		}

		try
		{
			$chat = $this->chatRepository->getByTaskId($event->task->id);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
			return;
		}

		$chatId = $chat?->getId();

		if ($chatId === null)
		{
			return;
		}

		self::lock();

		try
		{
			if (!$this->chat->muteChat($chatId, $event->user->id, true))
			{
				$this->logger->logWarning([
					'message' => 'Could not sync task chat mute',
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

		self::unlock();
	}
}
