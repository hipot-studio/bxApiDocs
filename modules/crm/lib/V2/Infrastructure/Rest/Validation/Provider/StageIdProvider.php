<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider;

use Bitrix\Crm\V2\Internal\Service\Item\Dictionary\StageDictionary;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * @internal
 */
class StageIdProvider implements ValidValuesProviderInterface, CacheKeyProviderInterface
{
	protected const DEFAULT_CATEGORY_ID = 0;

	public static function getCacheKey(Context $context): ?array
	{
		$entityTypeId = $context->getExtraArgs()['entityTypeId'] ?? null;
		if (!is_int($entityTypeId))
		{
			return null;
		}

		return [static::getEntityTypeId($context), static::getCategoryId($context)];
	}

	/**
	 * @return string[]
	 */
	public static function getValidValues(Context $context): array
	{
		$userId = $context->getUserId();
		$entityTypeId = static::getEntityTypeId($context);
		$categoryId = static::getCategoryId($context);

		return static::getDictionary()->getAllIdForEntityType(EntityType::fromId($entityTypeId), $categoryId, $userId);
	}

	protected static function getDictionary(): StageDictionary
	{
		return new StageDictionary();
	}

	protected static function getCategoryId(Context $context): int
	{
		if (static::getEntityTypeId($context) === OwnerType::DEAL) // Expected format "C{catId}:{stageId}"
		{
			$value = (string)$context->getValue();
			if (!str_contains($value, ':'))
			{
				return static::DEFAULT_CATEGORY_ID;
			}
			$categoryPart = explode(':', $value)[0] ?? static::DEFAULT_CATEGORY_ID;

			return (int)ltrim($categoryPart, 'C');
		}

		return static::DEFAULT_CATEGORY_ID;
	}

	protected static function getEntityTypeId(Context $context): int
	{
		return $context->getEntityType()->getId();
	}
}
