<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnTaskMutedUserSync;

use Bitrix\Im\Chat;
use Bitrix\Tasks\Internals\UserOption\Option;
use Bitrix\Tasks\V2\Internal\Entity\Task\UserOption;
use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskMutedUserSyncEvent;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Traits\MutexTrait;

class ChatSync
{
	use MutexTrait;

	public function __construct(
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly TaskUserOptionRepositoryInterface $taskUserOptionRepository,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnTaskMutedUserSyncEvent $event): void
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

		try
		{
			$options = $this->taskUserOptionRepository->get($event->task->id)->filter(
				fn(UserOption $option): bool => $option->code === Option::MUTED
			);
		}
		catch (\Throwable $e)
		{
			self::unlock();
			$this->logger->logError($e);
			return;
		}

		self::lock();

		foreach ($options as $option)
		{
			if ($option->userId === null)
			{
				continue;
			}

			try
			{
				Chat::mute(
					action: true,
					chatId: $chatId,
					userId: $option->userId,
				);
			}
			catch (\Throwable $e)
			{
				$this->logger->logError($e);
			}
		}

		self::unlock();
	}
}
