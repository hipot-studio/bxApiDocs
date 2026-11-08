<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Data\Cache;
use Bitrix\Mobile\Tab\Preset\Integration\Messenger\MenuCacheInvalidationMessage;

final class MobileMenuCacheInvalidator
{
	private const SYNCHRONOUS_USER_LIMIT = 100;
	private const QUEUE_MESSAGE_CHUNK_SIZE = 100;

	private const CACHE_DIRS = [
		'/mobile/menu/user_%d/',
		'/bx/mobile_menu/user_%d',
		'/bx/mobile_component/more/user_%d',
	];

	private readonly \Closure $cacheDirectoryCleaner;
	private readonly \Closure $cacheInvalidationScheduler;

	public function __construct(
		?\Closure $cacheDirectoryCleaner = null,
		?\Closure $cacheInvalidationScheduler = null,
	)
	{
		$this->cacheDirectoryCleaner = $cacheDirectoryCleaner ?? static function (string $cacheDir): void {
			Cache::createInstance()->cleanDir($cacheDir);
		};
		$this->cacheInvalidationScheduler = $cacheInvalidationScheduler ?? static function (array $userIds): void {
			(new MenuCacheInvalidationMessage($userIds))->send(MenuCacheInvalidationMessage::QUEUE_ID);
		};
	}

	public function clearUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		ActiveTabRequestCache::clearUser($userId);
		foreach (self::CACHE_DIRS as $cacheDir)
		{
			($this->cacheDirectoryCleaner)(sprintf($cacheDir, $userId));
		}
	}

	/**
	 * @param int[] $userIds
	 */
	public function clearUsers(array $userIds): void
	{
		$userIds = $this->normalizeUserIds($userIds);
		ActiveTabRequestCache::clearUsers($userIds);
		if (count($userIds) <= self::SYNCHRONOUS_USER_LIMIT)
		{
			$this->clearUsersImmediately($userIds);

			return;
		}

		foreach (array_chunk($userIds, self::QUEUE_MESSAGE_CHUNK_SIZE) as $userIdsChunk)
		{
			($this->cacheInvalidationScheduler)($userIdsChunk);
		}
	}

	private function clearUsersImmediately(array $userIds): void
	{
		foreach ($userIds as $userId)
		{
			$this->clearUser($userId);
		}
	}

	private function normalizeUserIds(array $userIds): array
	{
		$userIds = array_map('intval', $userIds);
		$userIds = array_filter($userIds, static fn(int $userId): bool => $userId > 0);

		return array_values(array_unique($userIds));
	}
}
