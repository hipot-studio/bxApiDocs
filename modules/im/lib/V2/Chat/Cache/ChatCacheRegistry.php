<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Cache;

use Bitrix\Im\V2\Cache\CacheConfig;
use Bitrix\Im\V2\Cache\CacheManager;
use Bitrix\Im\V2\Cache\PrimitiveCacheable;
use Bitrix\Im\V2\Cache\Registry\DomainCacheRegistry;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Cache\Mapper\PrimitiveMapper;

class ChatCacheRegistry extends DomainCacheRegistry
{
	private const CHAT_DATA = 'chat_data';
	private const GENERAL_CHAT_ID = 'general_chat_id';
	private const GENERAL_CHANNEL_ID = 'general_channel_id';
	private const GENERAL_CHAT_MANAGERS = 'general_chat_managers';

	/**
	 * @return CacheManager<Chat>
	 */
	public function getChatDataManager(): CacheManager
	{
		return $this->getManager(self::CHAT_DATA);
	}

	/**
	 * @return CacheManager<PrimitiveCacheable<int>>
	 */
	public function getGeneralChatIdManager(): CacheManager
	{
		return $this->getManager(self::GENERAL_CHAT_ID);
	}

	/**
	 * @return CacheManager<PrimitiveCacheable<int>>
	 */
	public function getGeneralChannelIdManager(): CacheManager
	{
		return $this->getManager(self::GENERAL_CHANNEL_ID);
	}

	/**
	 * @return CacheManager<PrimitiveCacheable<int[]>>
	 */
	public function getGeneralChatManagersManager(): CacheManager
	{
		return $this->getManager(self::GENERAL_CHAT_MANAGERS);
	}

	protected function load(): void
	{
		$this->register(
			new CacheConfig(
				entityType: self::CHAT_DATA,
				ttl: $this->getTtl(),
				domain: 'chat',
				version: 8,
				partitioningLevels: 1,
			),
			new ChatMapper()
		);

		$this->register(
			new CacheConfig(
				entityType: self::GENERAL_CHAT_ID,
				ttl: $this->getTtl(),
				domain: 'chat',
				version: 1,
			),
			new PrimitiveMapper()
		);

		$this->register(
			new CacheConfig(
				entityType: self::GENERAL_CHANNEL_ID,
				ttl: $this->getTtl(),
				domain: 'chat',
				version: 1,
			),
			new PrimitiveMapper()
		);

		$this->register(
			new CacheConfig(
				entityType: self::GENERAL_CHAT_MANAGERS,
				ttl: $this->getTtl(),
				domain: 'chat',
				version: 1,
			),
			new PrimitiveMapper()
		);
	}

	private function getTtl(): int
	{
		return defined("BX_COMP_MANAGED_CACHE") ? 18144000 : 1800;
	}
}