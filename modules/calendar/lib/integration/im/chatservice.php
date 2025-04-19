<?php

namespace Bitrix\Calendar\Integration\Im;

use Bitrix\Main\Loader;

final class ChatService
{
	private static ?self $instance;

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	public function deleteUserFromChat(int $userId, int $chatId): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		$chat = new \CIMChat(0);

		return $chat->DeleteUser(
			chatId: $chatId,
			userId: $userId,
			checkPermission: false,
			skipMessage: false,
			additionalParams: ['SKIP_RIGHTS' => true],
		);
	}

	public function addUserToChat(int $userId, int $chatId): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		$chat = new \CIMChat(0);

		return $chat->AddUser(
			chatId: $chatId,
			userId: $userId,
			hideHistory: false,
			skipMessage: false,
		);
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	private function __construct()
	{
	}
}
