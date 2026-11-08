<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Kanban\View;

use Bitrix\CrmMobile\Public\Feature\CompactListItem;
use Bitrix\Main\Config\Feature;

/**
 * The only place that decides whether item fields are taken from the compact field set.
 */
final class CompactViewResolver
{
	/**
	 * @return string[]|null null means the caller keeps its own legacy field rule
	 */
	public static function resolveShowedFields(int $entityTypeId): ?array
	{
		if (!self::isCompactView($entityTypeId))
		{
			return null;
		}

		return CompactFieldSet::forEntity($entityTypeId);
	}

	public static function isCompactView(int $entityTypeId): bool
	{
		if (!Feature::isEnabled(CompactListItem::class))
		{
			return false;
		}

		return ViewTypeStorage::get($entityTypeId) === ItemViewType::Compact;
	}
}
