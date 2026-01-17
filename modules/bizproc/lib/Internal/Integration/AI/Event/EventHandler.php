<?php

namespace Bitrix\Bizproc\Internal\Integration\AI\Event;

use Bitrix\Bizproc\Internal\Integration\AI\DTO\MessageCollection;
use Bitrix\Main\Event;

class EventHandler
{
	public const CONTEXT_MODULE = 'bizproc';
	public const CONTEXT_ID_CHAT_HISTORY = 'chatHistory';
	private const PARAMETER_MODULE = 'module';
	private const PARAMETER_CONTEXT_ID = 'id';

	public static function onContextGetMessages(Event $event): array
	{
		$module = $event->getParameter(self::PARAMETER_MODULE);
		if ($module !== self::CONTEXT_MODULE)
		{
			return self::makeEmptyResponse();
		}

		return match ($event->getParameter(self::PARAMETER_CONTEXT_ID))
		{
			self::CONTEXT_ID_CHAT_HISTORY => ChatHistoryAiEventHandler::makeChatHistoryResponse($event),
			default => self::makeEmptyResponse(),
		};
	}

	private static function makeEmptyResponse(): array
	{
		return (new MessageCollection())->toArray();
	}
}