<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\CurrencyDictionary;

/**
 * @internal
 */
class CurrencyIdProvider implements ValidValuesProviderInterface, CacheKeyProviderInterface
{
	public static function getCacheKey(Context $context): ?array
	{
		return [];
	}

	/**
	 * @return string[]
	 */
	public static function getValidValues(Context $context): array
	{
		return static::getDictionary()->getAllId();
	}

	protected static function getDictionary(): CurrencyDictionary
	{
		return new CurrencyDictionary();
	}
}
