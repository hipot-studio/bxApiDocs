<?php

namespace Bitrix\Bizproc\Internal\Integration\AI\Event;

use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Bizproc\Internal\Integration\AI\DTO\MessageCollection;
use Bitrix\Bizproc\Internal\Integration\ImBot\Builder\ChatHistory;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Event;

class ChatHistoryAiEventHandler
{
	public const PARAM_MESSAGE_ID = 'messageId';
	public const PARAM_BOT_ID = 'botId';
	public const PARAMETER_PARAMS = 'params';
	private const CONTEXT_AMOUNT_DEFAULT = 25;

	/**
	 * @param Event $event
	 *
	 * @return array{messages: list<array{content: string, role: string}>} Messages in descending order from new to old
	 */
	public static function makeChatHistoryResponse(Event $event): array
	{
		$messageId = (int)($event->getParameter(self::PARAMETER_PARAMS)[self::PARAM_MESSAGE_ID] ?? 0);
		$botId = (int)($event->getParameter(self::PARAMETER_PARAMS)[self::PARAM_BOT_ID] ?? 0);
		if (!$messageId || !$botId)
		{
			return self::makeEmptyResponse();
		}

		if (!ChatHistory::isSupported())
		{
			return self::makeEmptyResponse();
		}

		$message = new Message($messageId);
		if (!$message->getId() || !$message->getChatId())
		{
			return self::makeEmptyResponse();
		}

		$chat = $message->getChat();
		if ($chat->getType() !== Chat::IM_TYPE_PRIVATE)
		{
			return self::makeEmptyResponse();
		}

		if (!in_array($botId, $chat->getBotInChat(), true))
		{
			return self::makeEmptyResponse();
		}

		if (!BizprocBot::isExistsById($botId))
		{
			return self::makeEmptyResponse();
		}

		return (new ChatHistory($message, self::CONTEXT_AMOUNT_DEFAULT, $botId))
			->build()
		;
	}

	private static function makeEmptyResponse(): array
	{
		return (new MessageCollection())->toArray();
	}
}