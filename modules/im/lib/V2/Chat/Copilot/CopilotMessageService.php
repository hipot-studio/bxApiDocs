<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Event;

class CopilotMessageService
{
	private const MODULE_ID = 'im';
	private const EVENT_ON_REGENERATE = 'OnCopilotMessageRegenerate';

	public function sendRegenerateEvent(Chat $chat, Message $message, Message $triggerMessage, int $userId): void
	{
		$event = new Event(
			self::MODULE_ID,
			self::EVENT_ON_REGENERATE,
			[
				'chat' => $chat,
				'triggerMessage' => $triggerMessage,
				'message' => $message,
				'userId' => $userId,
			]
		);
		$event->send();
	}
}
