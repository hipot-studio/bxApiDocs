<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\StatusDictionary;

/**
 * @internal
 */
class StatusIdProvider implements ValidValuesProviderInterface, CacheKeyProviderInterface
{
	public static function getCacheKey(Context $context): ?array
	{
		$statusTypeId = $context->getExtraArgs()['statusTypeId'] ?? null;

		return is_string($statusTypeId) ? [$statusTypeId] : null;
	}

	/**
	 * @return string[]
	 */
	public static function getValidValues(Context $context): array
	{
		$statusTypeId = $context->getExtraArgs()['statusTypeId'] ?? null;
		if ($statusTypeId === null)
		{
			return [];
		}
		return static::getDictionary()->getAllIdByStatusTypeId($statusTypeId);
	}

	protected static function getDictionary(): StatusDictionary
	{
		return new StatusDictionary();
	}
}
