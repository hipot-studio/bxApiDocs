<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Anchor;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DI\ServiceLocator;

class AnchorProvider
{
	use ContextCustomer;

	private const CACHE_ID = 'user_anchors_v2_';
	private const CACHE_DIR = '/bx/imc/anchors/';
	private const CACHE_TTL = 60 * 60 * 24 * 30; // 1 month

	public function getUserAnchors(?int $userId = null): array
	{
		$userId ??= $this->getContext()->getUserId();
		if ($userId <= 0)
		{
			return [];
		}

		return $this->tryInitFromCache($userId);
	}

	public function cleanUsersCache(array $userIds): void
	{
		foreach ($userIds as $userId)
		{
			$this->cleanCache($userId);
		}
	}

	public function cleanCache(int $userId = 0): void
	{
		$cache = Application::getInstance()->getCache();

		if ($userId > 0)
		{
			$cacheId = self::CACHE_ID . $userId;
			$cacheDir = self::CACHE_DIR . $cacheId;

			$cache->clean($cacheId, $cacheDir);
		}
		else
		{
			$cache->cleanDir(self::CACHE_DIR);
		}
	}

	public function getChatDataMap(array $chatIds): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$map = array_fill_keys(
			$chatIds,
			['PARENT_CHAT_ID' => 0, 'PARENT_MESSAGE_ID' => 0, 'RECENT_SECTIONS' => []]
		);

		$rows = ChatTable::query()
			->setSelect(['ID', 'PARENT_ID', 'PARENT_MID', 'TYPE', 'ENTITY_TYPE'])
			->whereIn('ID', $chatIds)
			->fetchAll()
		;

		$recentConfigManager = RecentConfigManager::getInstance();
		$typeRegistry = ServiceLocator::getInstance()->get(TypeRegistry::class);

		foreach ($rows as $row)
		{
			$chatId = (int)$row['ID'];
			$chatParentId = (int)($row['PARENT_ID'] ?? 0);
			$messageParentId = (int)($row['PARENT_MID'] ?? 0);

			$type = $typeRegistry->getByLiteralAndEntity($row['TYPE'] ?? '', $row['ENTITY_TYPE'] ?? null);
			$extendedType = $type->getExtendedType(false);
			$recentSections = $chatParentId > 0
				? $recentConfigManager->getAllRecentSectionsByType($extendedType)
				: $recentConfigManager->getBaseRecentSections($extendedType);

			$map[$chatId] = [
				'PARENT_CHAT_ID' => $chatParentId,
				'PARENT_MESSAGE_ID' => $messageParentId,
				'RECENT_SECTIONS' => $recentSections,
			];
		}

		return $map;
	}

	public function getUserCache(int $userId): Cache
	{
		$cache = Application::getInstance()->getCache();

		$cacheId = self::CACHE_ID . $userId;
		$cacheDir = self::CACHE_DIR . $cacheId;

		$cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir);

		return $cache;
	}

	private function tryInitFromCache(int $userId): array
	{
		$cache = $this->getUserCache($userId);
		$anchors = $cache->getVars();
		if ($anchors === false)
		{
			$anchorCollection = AnchorCollection::find(['USER_ID' => $userId]);

			$chatDataMap = $this->getChatDataMap((array)$anchorCollection->getChatIdList());
			$anchorCollection->fillChatData($chatDataMap);

			$anchors = $anchorCollection->toRestFormat();

			$cache->startDataCache();
			$cache->endDataCache($anchors);
		}

		return $anchors;
	}
}