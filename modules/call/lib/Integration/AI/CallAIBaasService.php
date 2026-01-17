<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Main\Loader;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\AI;
use Bitrix\AI\Limiter\LimitControlService;
use Bitrix\AI\Limiter\LimitControlBoxService;
use Bitrix\AI\Integration\Baas\BaasTokenService;


class CallAIBaasService
{
	public const CALL_COPILOT_BAAS_SLIDER_CODE = 'limit_boost_copilot';

	private const
		CACHE_TTL = 3600,
		CACHE_DIR = 'call/baas';

	/**
	 * Checks availability baas service on portal.
	 * @return bool
	 */
	public static function isBaasServiceAvailable(): bool
	{
		static $available;
		if ($available === null)
		{
			$available = false;
			if (Loader::includeModule('ai'))
			{
				$available = ServiceLocator::getInstance()->get(BaasTokenService::class)->isAvailable();
			}
		}

		return $available;
	}

	/**
	 * Check if baas service has active packages.
	 * @return bool
	 */
	public static function checkQueryLimit(int $requestCount = 10): bool
	{
		if (self::isBaasServiceAvailable())
		{
			$cacheId = 'query_limit_'. $requestCount;
			$cache = Cache::createInstance();
			if ($cache->initCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
			{
				$data = $cache->getVars() ?? [];
			}
			else
			{
				$data = [];
				if (CallAISettings::isB24Mode())
				{
					$limitControl = ServiceLocator::getInstance()->get(LimitControlService::class);

					$reservedRequest = $limitControl?->reserveRequest(
						new AI\Limiter\Usage(AI\Context::getFake()),
						$requestCount
					);

					$data['isAllowedQuery'] = $reservedRequest?->isSuccess() ?? false;
				}
				else
				{
					$limitControl = ServiceLocator::getInstance()->get(LimitControlBoxService::class);

					$data['isAllowedQuery'] = $limitControl?->isAllowedQuery($requestCount)?->isSuccess() ?? false;
				}

				$cache->startDataCache(self::CACHE_TTL, $cacheId, self::CACHE_DIR);
				$cache->endDataCache($data);
			}

			return $data['isAllowedQuery'] ?? false;
		}

		return false;
	}


	public static function getBaasSliderCode(): string
	{
		return Option::get('call', 'call_ai_baas_code', self::CALL_COPILOT_BAAS_SLIDER_CODE);
	}

	public static function getBaasUrl(): string
	{
		return '/online/?FEATURE_PROMOTER='.self::CALL_COPILOT_BAAS_SLIDER_CODE;
	}
}
