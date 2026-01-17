<?php

namespace Bitrix\Rest\V3\Realisation\Controller;

use Bitrix\Main\Composite\Internals\Locker;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\DefaultLanguage;
use Bitrix\Rest\V3\Documentation\DocumentationManager;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\CacheManager;

class Documentation extends RestController
{
	private const DOCUMENTATION_CACHE_KEY = 'rest.v3.documentation.cache.key';

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	#[Title(new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_DOCUMENTATION_DOCUMENTATION_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_REALISATION_CONTROLLER_DOCUMENTATION_DOCUMENTATION_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	public function openApiAction(): ArrayResponse
	{
		if (!Locker::lock(self::DOCUMENTATION_CACHE_KEY))
		{
			throw new SystemException('Generation in progress.');
		}

		$cacheKey = self::DOCUMENTATION_CACHE_KEY . '.' . $this->responseLanguage;

		$result = CacheManager::get($cacheKey);
		if ($result === null)
		{
			$manager = new DocumentationManager($this->responseLanguage ?: DefaultLanguage::get());
			$result = $manager->generateDataForJson();
			CacheManager::set($cacheKey, $result, CacheManager::ONE_HOUR_TTL);
		}

		return (new ArrayResponse($result))->setShowDebugInfo(false)->setShowRawData(true);
	}
}
