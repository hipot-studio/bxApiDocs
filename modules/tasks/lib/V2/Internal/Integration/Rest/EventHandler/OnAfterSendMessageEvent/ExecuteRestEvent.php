<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\EventHandler\OnAfterSendMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterSendMessageEvent;
use Bitrix\Tasks\V2\Internal\Integration\Rest\Service\CommentEventService;

class ExecuteRestEvent
{
	public function __construct(
		private readonly CommentEventService $commentEventService,
	)
	{

	}
	public function __invoke(AfterSendMessageEvent $event): void
	{
		$message = $event->getMessage();
		$chat = $event->getChat();

		$this->commentEventService->executeRestEvent($message->getMessageId(), (int)$chat->getEntityId());
	}
}
