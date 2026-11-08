<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp;

use Bitrix\AiAssistant\Cache\Cache;
use Bitrix\AiAssistant\Definition\DefinitionRegistry;
use Bitrix\Crm\V2\Internal\Integration\Rest\V3\CacheManager;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

/**
 * @internal
 */
class PublicationCache
{
	public static function clear(): void
	{
		CacheManager::cleanAll();

		if (!static::isAiAssistantAvailable())
		{
			return;
		}

		$cache = ServiceLocator::getInstance()->get(Cache::class);
		$cache->remove(DefinitionRegistry::CACHE_KEY, 'combine_tools_Y');
		$cache->remove(DefinitionRegistry::CACHE_KEY, 'combine_tools_N');
	}

	protected static function isAiAssistantAvailable(): bool
	{
		return Loader::includeModule('aiassistant');
	}
}
