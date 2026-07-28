<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Cache\CacheManager;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Cache\ChatCacheRegistry;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Entity\User\User;
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

	/**
	 * Keeps the cached guest counter and the CONTAINS_GUEST chat param in sync
	 * when a guest's ACTIVE flag changes outside of add/remove-relation flow
	 * (e.g. {@see \Bitrix\Im\V2\Guest\CleanupService} deactivating stale guests).
	 */
	public static function onAfterUserUpdate(array $fields): void
	{
		if (!isset($fields['ACTIVE']))
		{
			return;
		}

		$userId = (int)($fields['ID'] ?? 0);
		if ($userId <= 0)
		{
			return;
		}

		if (!User::getInstance($userId)->isGuest())
		{
			return;
		}

		$chatIds = array_map(
			static fn(array $row): int => (int)$row['CHAT_ID'],
			RelationTable::query()
				->setSelect(['CHAT_ID'])
				->where('USER_ID', $userId)
				->fetchAll()
		);

		$shouldSyncContainsParam = $fields['ACTIVE'] === 'N';
		foreach ($chatIds as $chatId)
		{
			self::cleanCache($chatId);
			if ($shouldSyncContainsParam)
			{
				self::syncContainsGuestParam($chatId);
			}
		}
	}

	/**
	 * Drops CONTAINS_GUEST after the last active guest leaves the chat — the
	 * param is only managed on add/remove-relation, so deactivation via
	 * \CUser::Update would otherwise leave it stuck at true.
	 */
	private static function syncContainsGuestParam(int $chatId): void
	{
		$chat = ChatFactory::getInstance()->getChatById($chatId);
		if ($chat->getId() === null || !$chat->containsGuest())
		{
			return;
		}

		$hasActiveGuest = RelationTable::query()
			->setSelect(['ID'])
			->where('CHAT_ID', $chatId)
			->where('USER.EXTERNAL_AUTH_ID', UserGuest::AUTH_ID)
			->where('USER.ACTIVE', true)
			->where('IS_HIDDEN', false)
			->setLimit(1)
			->fetch()
		;
		if ($hasActiveGuest !== false)
		{
			return;
		}

		$chat->getChatParams()->deleteParam(Params::CONTAINS_GUEST);
	}

	private static function getCacheManager(): CacheManager
	{
		return ServiceLocator::getInstance()
			->get(ChatCacheRegistry::class)
			->getGuestCountManager()
		;
	}
}
