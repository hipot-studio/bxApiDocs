<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Main\Loader;

class ChatReadAllMessageService
{
	private readonly ?ChatFactory $chatFactory;

	public function __construct(?ChatFactory $chatFactory = null)
	{
		if (!Loader::includeModule('im'))
		{
			$this->chatFactory = null;

			return;
		}

		$this->chatFactory = $chatFactory ?? ChatFactory::getInstance();
	}

	public function readAllByChatId(int $userId, int $chatId): void
	{
		$chat = $this->getChatForUser($userId, $chatId);

		if (!$chat)
		{
			return;
		}

		$this->readAll($chat);
	}

	private function readAll(Chat $chat): void
	{
		$chat->readAllMessages();
	}

	private function getChatForUser(int $userId, int $chatId): ?Chat
	{
		return $this->chatFactory
			?->getChatById($chatId)
			->withContextUser($userId);
	}
}
