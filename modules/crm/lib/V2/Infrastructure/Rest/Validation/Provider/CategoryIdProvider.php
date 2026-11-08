<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\CategoryDictionary;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
class CategoryIdProvider implements ValidValuesProviderInterface, CacheKeyProviderInterface
{
	public static function getCacheKey(Context $context): ?array
	{
		$entityTypeId = $context->getExtraArgs()['entityTypeId'] ?? null;

		return is_int($entityTypeId) ? [$entityTypeId] : null;
	}

	/**
	 * @return int[]
	 */
	public static function getValidValues(Context $context): array
	{
		$userId = $context->getUserId();
		$entityType = $context->getEntityType();

		return static::getDictionary()->getAllId($entityType, $userId);
	}

	protected static function getDictionary(): CategoryDictionary
	{
		return new CategoryDictionary();
	}
}
