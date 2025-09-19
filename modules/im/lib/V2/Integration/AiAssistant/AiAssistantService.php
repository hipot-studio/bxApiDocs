<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant;

use Bitrix\AiAssistant\Core\Service\Bot\BotManager;
use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class AiAssistantService
{
	private const AI_ASSISTANT_CHAT_CREATION_FEATURE_FLAG = 'ai_assistant_chat_creation_available';

	private ?BotManager $botManager = null;

	public function __construct()
	{
		if (Loader::includeModule('aiassistant'))
		{
			$this->botManager = ServiceLocator::getInstance()->get(BotManager::class);
		}
	}

	public function isAiAssistantAvailable(): bool
	{
		return $this->botManager?->isAvailable() ?? false;
	}

	public function isAiAssistantChatCreationAvailable(): bool
	{
		return
			$this->isAiAssistantAvailable()
			&& Option::get('im', self::AI_ASSISTANT_CHAT_CREATION_FEATURE_FLAG, 'N') === 'Y'
		;
	}

	public function getBotId(): int
	{
		return $this->botManager?->getBotId() ?? 0;
	}

	public function getPersonalAiAssistantChatId(int $userId): int
	{
		// TODO: add cache!!!
		$botId = $this->getBotId();
		if ($botId === 0)
		{
			return 0;
		}

		return \CIMMessage::GetChatId($botId, $userId, false);
	}

	public function isAiAssistantChat(Chat $chat): bool
	{
		if ($this->getBotId() === 0)
		{
			return false;
		}

		if (!($chat instanceof Chat\PrivateChat))
		{
			return false;
		}

		return $chat->getRelationByUserId($this->getBotId()) !== null;
	}
}
