<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\Data\Cache;

final class UserBlockService
{
	private const CACHE_TTL = 30 * 60; // 30 minutes
	private const CACHE_DIR = '/bizprocdesigner/user_selected_block';
	private const CACHE_PARAM = 'ID';

	public function set(int $userId, ?string $blockId = null): void
	{
		if (empty($blockId))
		{
			$this->remove($userId);

			return;
		}

		$this->save($userId, $blockId);
	}

	public function get(int $userId): ?string
	{
		$cache = Cache::createInstance();
		if ($cache->initCache(self::CACHE_TTL, $userId, self::CACHE_DIR))
		{
			return $cache->getVars()[self::CACHE_PARAM] ?? null;
		}

		return null;
	}

	private function remove(int $userId): void
	{
		Cache::createInstance()->clean($userId, self::CACHE_DIR);
	}

	private function save(int $userId, string $blockId): void
	{
		$cache = Cache::createInstance();
		$cache->forceRewriting(true);
		$cache->startDataCache(self::CACHE_TTL, $userId, self::CACHE_DIR);
		$cache->endDataCache([self::CACHE_PARAM => $blockId]);
	}
}