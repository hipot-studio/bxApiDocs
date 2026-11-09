<?php

namespace Bitrix\BIConnector\Access\Rule;

use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\Main\Access\AccessibleItem;

/**
 * Creating, editing and deleting the table groups.
 *
 * Before the table axis is on the groups are only folders of the section of tables, so they are
 * arranged by whoever works in the analytics workspace. With the axis on a group is what the rights on
 * tables are given for, and it is arranged by the manager of these rights.
 */
final class TableGroupManageRule extends BaseRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if (!Feature::isExternalEntitiesEnabled())
		{
			return false;
		}

		return parent::execute($item, $params);
	}

	protected function getPermissionId(string $action): ?string
	{
		return (string)(
			Feature::isTablePermissionsEnabled()
				? PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS
				: PermissionDictionary::BIC_EXTERNAL_DASHBOARD_CONFIG
		);
	}
}
