<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnTaskMuted;

use Bitrix\Im\Chat;
use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskMutedEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Traits\MutexTrait;

class ChatSync
{
	use MutexTrait;

	public function __construct(
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly TaskReadRepositoryInterface $taskRepository,
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
			Chat::mute(
				action: true,
				chatId: $chatId,
				userId: $event->user->id,
			);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}

		self::unlock();
	}
}
