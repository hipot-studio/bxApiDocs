<?php

namespace Bitrix\Sign\Integration\Main;

use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Document\Placeholder\PlaceholderCacheService;

class DocumentPlaceholderCacheEventHandler
{
	private const RELEVANT_ENTITY_IDS = [
		'USER_LEGAL',
		'CRM_COMPANY',
		'CRM_SMART_B2E_DOC',
		'SIGN_MEMBER_DYNAMIC',
	];

	private static ?PlaceholderCacheService $placeholderCacheService = null;

	public static function onAfterAddField(array $fieldData): void
	{
		if (self::isRelevantEntityId($fieldData))
		{
			self::getPlaceholderCacheService()->invalidateDocumentPlaceholderListCache();
		}
	}

	public static function onAfterDeleteField(array $fieldData): void
	{
		if (self::isRelevantEntityId($fieldData))
		{
			self::getPlaceholderCacheService()->invalidateDocumentPlaceholderListCache();
		}
	}

	private static function isRelevantEntityId(array $fieldData): bool
	{
		return in_array($fieldData['ENTITY_ID'] ?? '', self::RELEVANT_ENTITY_IDS, true);
	}

	private static function getPlaceholderCacheService(): PlaceholderCacheService
	{
		if (self::$placeholderCacheService === null)
		{
			self::$placeholderCacheService = Container::instance()->getDocumentPlaceholderCacheService();
		}

		return self::$placeholderCacheService;
	}
}
