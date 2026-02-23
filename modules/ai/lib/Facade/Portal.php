<?php

declare(strict_types=1);

namespace Bitrix\AI\Facade;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use CBitrix24;

class Portal
{
	private const DEFAULT_REGION = 'en';

	private const CACHE_ID = 'bx_ai_portal_creation_time';
	private const CACHE_DIR = '/bx/ai/portal';

	public static function getCreationDateTime(): ?DateTime
	{
		if (Loader::includeModule('bitrix24'))
		{
			$timestamp = (int)CBitrix24::getCreateTime();

			return $timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null;
		}

		$cache = Cache::createInstance();

		if ($cache->initCache(31536000, self::CACHE_ID, self::CACHE_DIR))
		{
			$timestamp = (int)($cache->getVars() ?? 0);

			return $timestamp > 0 ? DateTime::createFromTimestamp($timestamp) : null;
		}

		if (!$cache->startDataCache())
		{
			return null;
		}

		$firstUser = UserTable::query()
			->setSelect(['ID', 'DATE_REGISTER'])
			->where('ID', 1)
			->setLimit(1)
			->fetchObject();

		$timestamp = (int)($firstUser?->getDateRegister()?->getTimestamp() ?: 0);

		if ($timestamp <= 0)
		{
			$cache->abortDataCache();

			return null;
		}

		$cache->endDataCache($timestamp);

		return DateTime::createFromTimestamp($timestamp);
	}

	public static function getRegion(): string
	{
		return Application::getInstance()->getLicense()->getRegion() ?? self::DEFAULT_REGION;
	}
}
