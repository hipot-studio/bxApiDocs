<?php

namespace Bitrix\Rest\V3\Realisation\Controller;

use Bitrix\Main\Composite\Internals\Locker;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\NotIdempotent;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\DefaultLanguage;
use Bitrix\Rest\V3\Documentation\DocumentationManager;
use Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumRegistry;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

final class Documentation extends RestController
{
	private const DOCUMENTATION_CACHE_KEY = 'rest.v3.documentation.cache.key';

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	#[Title(new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_DOCUMENTATION_DOCUMENTATION_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_DOCUMENTATION_DOCUMENTATION_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	#[NotIdempotent]
	public function openApiAction(): ArrayResponse
	{
		$generation = DynamicEnumRegistry::getGeneration();
		$cacheKey = self::DOCUMENTATION_CACHE_KEY . '.' . $this->responseLanguage;

		// the lock guards generation only, a warm cache must never wait for it
		$result = $this->getCachedOpenApi($cacheKey, $generation);
		if ($result === null)
		{
			if (!Locker::lock(self::DOCUMENTATION_CACHE_KEY))
			{
				throw new SystemException('Generation in progress.');
			}

			try
			{
				$result = $this->getCachedOpenApi($cacheKey, $generation);
				if ($result === null)
				{
					$manager = new DocumentationManager($this->responseLanguage ?: DefaultLanguage::get());
					$result = $manager->generateDataForJson();
					CacheManager::set($cacheKey, [
						'dynamicEnumGeneration' => $generation,
						'openApi' => $result,
					], CacheManager::ONE_HOUR_TTL);
				}
			}
			finally
			{
				Locker::unlock(self::DOCUMENTATION_CACHE_KEY);
			}
		}

		return (new ArrayResponse($result))->setShowDebugInfo(false)->setShowRawData(true);
	}

	private function getCachedOpenApi(string $cacheKey, int $generation): ?array
	{
		$cached = CacheManager::get($cacheKey);

		return is_array($cached)
			&& ($cached['dynamicEnumGeneration'] ?? null) === $generation
			&& is_array($cached['openApi'] ?? null)
				? $cached['openApi']
				: null;
	}
}
