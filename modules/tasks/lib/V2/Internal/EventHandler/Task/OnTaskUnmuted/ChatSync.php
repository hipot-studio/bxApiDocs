<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnTaskUnmuted;

use Bitrix\Im\Chat;
use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskUnmutedEvent;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;

class ChatSync
{
	public function __construct(
		private readonly ChatRepositoryInterface $repository,
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

		Chat::mute(
			action: false,
			chatId: $chatId,
			userId: $event->user->id,
		);
	}
}
