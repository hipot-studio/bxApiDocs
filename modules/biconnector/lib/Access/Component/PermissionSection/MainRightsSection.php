<?php

namespace Bitrix\BIConnector\Access\Component\PermissionSection;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Component\PermissionConfig;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Configuration\Feature;

/**
 * Section of the module rights: the togglers that belong to no axis.
 */
final class MainRightsSection extends BaseSection
{
	/**
	 * @var string[]|null
	 */
	private ?array $ownIds = null;

	public function getCode(): string
	{
		return PermissionConfig::SECTION_MAIN_RIGHTS;
	}

	public function getRights(): array
	{
		$rights = [];
		foreach ($this->getPermissionIds() as $permissionId)
		{
			$rights[] = PermissionDictionary::getPermission($permissionId);
		}

		return $rights;
	}

	/**
	 * Asked for every row of every role while the matrix is built and saved, so the list is made once:
	 * it depends on feature checks that do not change within a hit.
	 */
	public function ownsPermissionId(int|string $permissionId): bool
	{
		$this->ownIds ??= array_map('strval', $this->getPermissionIds());

		return in_array((string)$permissionId, $this->ownIds, true);
	}

	protected function getEditAction(): string
	{
		return ActionDictionary::ACTION_BIC_SETTINGS_EDIT_RIGHTS;
	}

	/**
	 * @return int[]
	 */
	private function getPermissionIds(): array
	{
		$permissionIds = [
			PermissionDictionary::BIC_ACCESS,
			PermissionDictionary::BIC_DASHBOARD_TAG_MODIFY,
			PermissionDictionary::BIC_DASHBOARD_SHARE,
			PermissionDictionary::BIC_SETTINGS_ACCESS,
			PermissionDictionary::BIC_SETTINGS_EDIT_RIGHTS,
			PermissionDictionary::BIC_SECTION_EDIT_DASHBOARD_RIGHTS,
		];

		if (Feature::isTablePermissionsEnabled())
		{
			$permissionIds[] = PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS;
		}

		if (!in_array((string)PermissionDictionary::BIC_SHARE_CONTROL, PermissionConfig::getHiddenPermissionIds(), true))
		{
			array_splice($permissionIds, 3, 0, [PermissionDictionary::BIC_SHARE_CONTROL]);
		}

		if (Feature::isExternalEntitiesEnabled())
		{
			$permissionIds[] = PermissionDictionary::BIC_EXTERNAL_DASHBOARD_CONFIG;
			$permissionIds[] = PermissionDictionary::BIC_DELETE_ALL_UNUSED_ELEMENTS;
		}

		return $permissionIds;
	}
}
