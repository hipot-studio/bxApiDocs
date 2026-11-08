<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

final class LayoutContextResolver
{
	public function resolve(int $entityTypeId, int $categoryId, ?LayoutId $layoutId = null): Result
	{
		$variant = $layoutId?->getVariant() ?? LayoutVariant::General;
		if (
			$categoryId < 0
			|| ($variant === LayoutVariant::Returning && ($entityTypeId !== CCrmOwnerType::Lead || $categoryId !== 0))
		)
		{
			return self::unsupportedContextResult();
		}

		if (in_array($entityTypeId, [CCrmOwnerType::Lead, CCrmOwnerType::Quote], true))
		{
			if ($categoryId !== 0)
			{
				return self::unsupportedContextResult();
			}

			$resolvedCategoryId = null;
		}
		elseif (in_array($entityTypeId, [CCrmOwnerType::Deal, CCrmOwnerType::Contact, CCrmOwnerType::Company], true))
		{
			$resolvedCategoryId = $categoryId;
		}
		else
		{
			$resolvedCategoryId = $categoryId === 0
				? Container::getInstance()->getFactory($entityTypeId)?->getDefaultCategory()?->getId()
				: $categoryId
			;
			if ($resolvedCategoryId === null || $resolvedCategoryId <= 0)
			{
				return self::unsupportedContextResult();
			}
		}

		return LayoutContext::create($entityTypeId, $resolvedCategoryId, LayoutMode::Update, $variant);
	}

	private static function unsupportedContextResult(): Result
	{
		return (new Result())->addError(
			new Error('The layout context is not supported.', 'UNSUPPORTED_CONTEXT'),
		);
	}
}
