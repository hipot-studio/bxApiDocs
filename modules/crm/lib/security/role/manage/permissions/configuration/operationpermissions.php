<?php

namespace Bitrix\Crm\Security\Role\Manage\Permissions\Configuration;

use Bitrix\Crm\Security\Role\Manage\PermissionAttrPresets;
use Bitrix\Crm\Security\Role\Manage\Permissions\Permission;
use Bitrix\Crm\Service\UserPermissions\ConfigurationOperation;

/**
 * The granular configuration operations as a configuration entity of a contour declares them, in the
 * order of {@see ConfigurationOperation::storedOperations()}.
 *
 * One list for both contours on purpose. A contour that declares only some of the operations is read
 * as a contour that declares none and keeps the former check of every asking surface
 * ({@see \Bitrix\Crm\Service\UserPermissions\Configuration}), and the CRM contour is the one the whole
 * model is activated by - so a second copy of the list that drifted from this one would silently take
 * the new rights away instead of failing.
 */
final class OperationPermissions
{
	/**
	 * @return Permission[]
	 */
	public static function all(): array
	{
		return [
			new ManageFunnels(PermissionAttrPresets::allowedYesNo()),
			new ManageStages(PermissionAttrPresets::allowedYesNo()),
			new ManageFields(PermissionAttrPresets::allowedYesNo()),
			new DeleteFields(PermissionAttrPresets::allowedYesNo()),
			new ManageCommonViews(PermissionAttrPresets::allowedYesNo()),
			new ManageBizprocTemplates(PermissionAttrPresets::allowedYesNo()),
			new ManageDynamicTypes(PermissionAttrPresets::allowedYesNo()),
		];
	}
}
