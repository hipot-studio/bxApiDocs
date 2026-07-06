<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Pin;

use Bitrix\Main\Application;

class PinCache
{
	private const TTL = 86400;
	private const BASE_DIR = '/bx/imc/pin';
	private array $inMemoryCache = [];

	public function getByScope(int $userId, int $parentId): ?PinScopeState
	{
		if (isset($this->inMemoryCache[$userId][$parentId]))
		{
			return $this->inMemoryCache[$userId][$parentId];
		}

		$cacheKey = $this->makeKey($userId, $parentId);
		$cacheDir = $this->makeDir($userId);
		$cache = Application::getInstance()->getCache();

		if (!$cache->initCache(self::TTL, $cacheKey, $cacheDir))
		{
			return null;
		}

		$data = $cache->getVars();

		if (!is_array($data))
		{
			return null;
		}

		$state = PinScopeState::fromCacheArray($data);
		$this->inMemoryCache[$userId][$parentId] = $state;

		return $state;
	}

	public function setByScope(int $userId, int $parentId, PinScopeState $state): void
	{
		$this->inMemoryCache[$userId][$parentId] = $state;

		$cacheKey = $this->makeKey($userId, $parentId);
		$cacheDir = $this->makeDir($userId);
		$cache = Application::getInstance()->getCache();

		$cache->initCache(self::TTL, $cacheKey, $cacheDir);

		if (!$cache->startDataCache())
		{
			return;
		}

		$taggedCache = Application::getInstance()->getTaggedCache();
		$taggedCache->startTagCache($cacheDir);
		$taggedCache->registerTag($this->makeUserTag($userId));

		$cache->endDataCache($state->toCacheArray());

		$taggedCache->endTagCache();
	}

	public function clearByUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		unset($this->inMemoryCache[$userId]);

		Application::getInstance()->getTaggedCache()->clearByTag($this->makeUserTag($userId));
	}

	private function makeKey(int $userId, int $parentId): string
	{
		return 'pin_scope_' . $userId . '_' . $parentId;
	}

	private function makeDir(int $userId): string
	{
		$partition = $userId % 100;

		return self::BASE_DIR . '/' . $partition . '/' . $userId;
	}

	private function makeUserTag(int $userId): string
	{
		return 'pin_user_' . $userId;
	}
}
