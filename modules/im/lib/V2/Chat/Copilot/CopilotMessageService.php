<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Copilot;

use Bitrix\Im\V2\Analytics\CopilotAnalytics;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Service\Context;
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

		// BitrixGPT v2 ai-label: message_regenerate for copilot chats under flag
		if ($chat instanceof CopilotChat && Features::isBitrixGptV2Available())
		{
			$context = new Context();
			$context->setUserId($userId);

			(new CopilotAnalytics($chat))
				->withContext($context)
				->addMessageRegenerate($message, $triggerMessage)
			;
		}
	}
}
