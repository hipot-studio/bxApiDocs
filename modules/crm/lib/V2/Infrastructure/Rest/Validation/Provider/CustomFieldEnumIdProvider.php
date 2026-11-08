<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\CustomFieldEnumDictionary;

/**
 * @internal
 */
class CustomFieldEnumIdProvider implements ValidValuesProviderInterface, CacheKeyProviderInterface
{
	public static function getCacheKey(Context $context): ?array
	{
		$customFieldId = $context->getExtraArgs()['customFieldId'] ?? null;

		return is_scalar($customFieldId) ? [(int)$customFieldId] : null;
	}

	/**
	 * @return int[]
	 */
	public static function getValidValues(Context $context): array
	{
		$customFieldId = $context->getExtraArgs()['customFieldId'] ?? null;
		if ($customFieldId === null)
		{
			return [];
		}
		return static::getDictionary()->getEnumIds((int)$customFieldId);
	}

	protected static function getDictionary(): CustomFieldEnumDictionary
	{
		return new CustomFieldEnumDictionary();
	}
}
