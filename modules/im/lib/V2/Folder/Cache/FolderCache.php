<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Cache;

use Bitrix\Main\Application;

class FolderCache
{
	private const TTL = 86400;
	private const BASE_DIR = '/bx/imc/folder/v2/';
	private const TAG_SYSTEM = 'folder_feature_system';

	private array $inMemoryCache = [];

	public function get(int $userId, int $parentId): ?FolderListData
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

		$listData = FolderListData::fromArray($data);
		$this->inMemoryCache[$userId][$parentId] = $listData;

		return $listData;
	}

	public function set(int $userId, int $parentId, FolderListData $data): void
	{
		$this->inMemoryCache[$userId][$parentId] = $data;

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
		$taggedCache->registerTag(self::TAG_SYSTEM);

		$cache->endDataCache($data->toArray());

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

	public function clearAllSystem(): void
	{
		$this->inMemoryCache = [];

		Application::getInstance()->getTaggedCache()->clearByTag(self::TAG_SYSTEM);
	}

	private function makeKey(int $userId, int $parentId): string
	{
		return 'folder_user_' . $userId . '_parent_' . $parentId;
	}

	private function makeDir(int $userId): string
	{
		$partition = $userId % 100;

		return self::BASE_DIR . '/' . $partition . '/' . $userId;
	}

	private function makeUserTag(int $userId): string
	{
		return 'folder_user_' . $userId;
	}
}
