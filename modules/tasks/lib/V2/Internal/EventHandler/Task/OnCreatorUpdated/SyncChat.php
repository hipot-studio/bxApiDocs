<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnCreatorUpdated;

use Bitrix\Tasks\V2\Internal\Event\Task\OnCreatorUpdatedEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Service\UpdateChatOwnerService;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;

class SyncChat
{
	public function __construct(
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly UpdateChatOwnerService $service,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnCreatorUpdatedEvent $event): void
	{
		$chat = $this->chatRepository->getByTaskId($event->task->id);

		if (null === $chat)
		{
			return;
		}

		try
		{
			$this->service->handle($chat->getId(), $event->newCreator->id, $event->previousCreator->id);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
}
