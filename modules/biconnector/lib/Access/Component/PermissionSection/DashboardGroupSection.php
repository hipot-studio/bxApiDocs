<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Component\PermissionConfig;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;

/**
 * Section of the dashboard group rights: one row per group of dashboards.
 */
final class DashboardGroupSection extends BaseSection
{
	/**
	 * Prefix of a row whose group is not created yet.
	 */
	public const PLACEHOLDER_PREFIX = 'new_G';

	public function getCode(): string
	{
		return PermissionConfig::SECTION_DASHBOARD_GROUP_RIGHTS;
	}

	public function canAddGroup(): bool
	{
		return true;
	}

	public function getRights(): array
	{
		$rights = [];
		foreach (array_keys(PermissionDictionary::getDashboardGroupPermissions()) as $permissionId)
		{
			$rights[] = PermissionDictionary::getPermission($permissionId);
		}

		return $rights;
	}

	public function ownsPermissionId(int|string $permissionId): bool
	{
		if (!is_string($permissionId))
		{
			return false;
		}

		if (str_starts_with($permissionId, self::PLACEHOLDER_PREFIX))
		{
			return true;
		}

		if (PermissionDictionary::isTableGroupPermission($permissionId))
		{
			return false;
		}

		return PermissionDictionary::isDashboardGroupPermission($permissionId);
	}

	protected function getEditAction(): string
	{
		return ActionDictionary::ACTION_BIC_SECTION_EDIT_DASHBOARD_RIGHTS;
	}
}
