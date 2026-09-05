<?php

namespace Bitrix\Im\V2\Integration\Sign;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Sign\Config\User;

class DocumentSign
{
	private const CACHE_ID = 'b2b_document_available_';
	private const CACHE_DIR = '/bx/im/sign/document_available/';
	private const CACHE_TTL = 86400;

	public static function isAvailable(): bool
	{
		if (!Loader::includeModule('sign'))
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId < 1)
		{
			return false;
		}

		$cache = self::getCache($userId);
		$vars = $cache->getVars();
		if (is_array($vars) && array_key_exists('isAvailable', $vars))
		{
			return (bool)$vars['isAvailable'];
		}

		$isAvailable = User::instance()->isB2bCreateDocumentAvailableForCurrentUser();
		if ($cache->startDataCache())
		{
			$cache->endDataCache(['isAvailable' => $isAvailable]);
		}

		return $isAvailable;
	}

	private static function getCache(int $userId): Cache
	{
		$cache = Application::getInstance()->getCache();

		$cacheTTL = self::CACHE_TTL;
		$cacheId = self::CACHE_ID . $userId;
		$cacheDir = self::getCacheDir($userId);

		$cache->initCache($cacheTTL, $cacheId, $cacheDir);

		return $cache;
	}

	private static function getCacheDir(int $userId): string
	{
		$cacheSubDir = $userId % 100;

		return self::CACHE_DIR . "{$cacheSubDir}/{$userId}";
	}
}
