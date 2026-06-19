<?php

namespace Bitrix\ImOpenLines\V2\Integration\AiAssistant\EventHandler;

use Bitrix\Bizproc\Internal\Integration\ImBot\Builder\ChatHistory;
use Bitrix\Im\V2\Message;
use Bitrix\ImBot\Integration\Im\Repository\OpenLinesBotRepository;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;

final class AiAssistantAgentActivity
{
	private const CHAT_HISTORY_MESSAGES_LIMIT = 100;

	public static function onCollectCustomContext(Event $event): EventResult
	{
		if (
			!Loader::includeModule('imbot')
			|| !Loader::includeModule('im')
			|| !Loader::includeModule('bizproc')
			|| !ChatHistory::isSupported()
		)
		{
			return self::fail();
		}

		$triggerEventData = $event->getParameter('triggerEventData');
		if (empty($triggerEventData))
		{
			return self::fail();
		}

		$usePseudonymizer = $event->getParameter('usePseudonymizer') === true;

		$salt = $event->getParameter('salt');
		$salt = is_numeric($salt) ? (int)$salt : 0;

		$messageId = (int)($triggerEventData['ID'] ?? 0);
		if ($messageId <= 0)
		{
			return self::fail();
		}

		$message = new Message($messageId);
		if (!$message->getChat()->isExist())
		{
			return self::fail();
		}

		$botId = (int)($triggerEventData['BOT_ID'] ?? 0);
		if ($botId <= 0)
		{
			return self::fail();
		}

		$isBotExists = self::getOpenLinesBotRepository()?->isExists($botId) ?? false;
		if (!$isBotExists)
		{
			return self::fail();
		}

		$isBotInChat = in_array($botId, $message->getChat()->getBotInChat(), true);
		if (!$isBotInChat)
		{
			return self::fail();
		}

		$chatHistoryBuilder = new ChatHistory(
			$message,
			self::CHAT_HISTORY_MESSAGES_LIMIT,
			$botId,
			$salt,
			$usePseudonymizer,
		);

		$chatHistory = $chatHistoryBuilder->build();
		if (!is_array($chatHistory['messages'] ?? null))
		{
			return self::fail();
		}

		$chatHistory['messages'] = array_reverse($chatHistory['messages']);

		return new EventResult(EventResult::SUCCESS, [
			'chatHistory' => $chatHistory,
		]);
	}

	private static function fail(): EventResult
	{
		return new EventResult(EventResult::ERROR);
	}

	private static function getOpenLinesBotRepository(): ?OpenLinesBotRepository
	{
		if (!Loader::includeModule('imbot'))
		{
			return null;
		}

		return ServiceLocator::getInstance()->get(OpenLinesBotRepository::class);
	}
}
