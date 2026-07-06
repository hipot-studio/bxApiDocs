<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Cache\CacheManager;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Cache\ChatCacheRegistry;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Counts guest users (EXTERNAL_AUTH_ID = im_guest) in a chat.
 */
class GuestCounter
{
	protected Chat $chat;

	public function __construct(Chat $chat)
	{
		$this->chat = $chat;
	}

	public function getGuestCount(): int
	{
		$result = self::getCacheManager()
			->getOrSet(
				entityId: $this->chat->getId(),
				dataProvider: fn() => $this->countByQuery(),
			)
		;

		return $result->getResult()?->value ?? 0;
	}

	protected function countByQuery(): int
	{
		return RelationTable::getCount([
			'=CHAT_ID' => $this->chat->getId(),
			'=USER.EXTERNAL_AUTH_ID' => UserGuest::AUTH_ID,
			'=USER.ACTIVE' => true,
			'=IS_HIDDEN' => false,
		]);
	}

	public static function cleanCache(int $chatId): void
	{
		self::getCacheManager()->clear(entityId: $chatId);
	}

	private static function getCacheManager(): CacheManager
	{
		return ServiceLocator::getInstance()
			->get(ChatCacheRegistry::class)
			->getGuestCountManager()
		;
	}
}
