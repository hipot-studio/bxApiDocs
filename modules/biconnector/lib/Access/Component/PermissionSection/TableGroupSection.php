<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Component\PermissionConfig;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;

/**
 * Section of the table group rights: one row per group of tables.
 */
final class TableGroupSection extends BaseSection
{
	/**
	 * Prefix of a row whose group is not created yet.
	 */
	public const PLACEHOLDER_PREFIX = 'new_T';

	public function getCode(): string
	{
		return PermissionConfig::SECTION_TABLE_GROUP_RIGHTS;
	}

	public function canAddGroup(): bool
	{
		return true;
	}

	public function getRights(): array
	{
		$subtitles = PermissionDictionary::getTableGroupSubtitles();

		$rights = [];
		foreach (array_keys(PermissionDictionary::getTableGroupPermissions()) as $permissionId)
		{
			$right = PermissionDictionary::getPermission($permissionId);
			// The caption costs a read of the whole table catalog, so it is asked for here: the
			// slider is the only reader that shows it.
			$right['subtitle'] = $subtitles[$permissionId] ?? '';
			$rights[] = $right;
		}

		return $rights;
	}

	public function ownsPermissionId(int|string $permissionId): bool
	{
		if (!is_string($permissionId))
		{
			return false;
		}

		return str_starts_with($permissionId, self::PLACEHOLDER_PREFIX)
			|| PermissionDictionary::isTableGroupPermission($permissionId)
		;
	}

	protected function getEditAction(): string
	{
		return ActionDictionary::ACTION_BIC_SETTINGS_EDIT_TABLE_RIGHTS;
	}
}
