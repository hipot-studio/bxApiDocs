<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Kanban\View;

/**
 * The only place where the item view type of the current user is read and written.
 */
final class ViewTypeStorage
{
	private const OPTION_CATEGORY = 'crmmobile';
	private const OPTION_NAME_PREFIX = 'item_view_type_';

	public static function get(int $entityTypeId): ItemViewType
	{
		$storedValue = \CUserOptions::GetOption(
			self::OPTION_CATEGORY,
			self::getOptionName($entityTypeId),
		);

		return ItemViewType::fromStringOrDefault(is_string($storedValue) ? $storedValue : null);
	}

	public static function set(int $entityTypeId, ItemViewType $type): bool
	{
		return \CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::getOptionName($entityTypeId),
			$type->value,
		);
	}

	private static function getOptionName(int $entityTypeId): string
	{
		return self::OPTION_NAME_PREFIX . mb_strtolower(\CCrmOwnerType::ResolveName($entityTypeId));
	}
}
