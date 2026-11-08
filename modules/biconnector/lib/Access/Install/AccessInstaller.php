<?php

namespace Bitrix\BIConnector\Access\Install;

use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleRelationTable;
use Bitrix\BIConnector\Access\Role\RoleTable;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Access\TableGroup\TableGroupBindingTable;
use Bitrix\BIConnector\Access\TableGroup\TableGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupBindingTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupScopeTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Superset\Scope\ScopeService;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class AccessInstaller
{
	public static function install($isNewPortal = true, ?RoleMap $roleMap = null): Result
	{
		$result = new Result();
		if ($roleMap === null)
		{
			$roleMap = self::getRoleMap($isNewPortal);
		}

		$installGroupResult = self::installDefaultGroups();
		if (!$installGroupResult->isSuccess())
		{
			$result->addErrors($installGroupResult->getErrors());

			return $result;
		}

		// Roles do not depend on table groups, so a failure here must not leave the portal without roles.
		$installTableGroupResult = TableGroupInstaller::install();
		if (!$installTableGroupResult->isSuccess())
		{
			$result->addErrors($installTableGroupResult->getErrors());
		}

		// The composition follows the groups and by the same rule. The catalog is built by the data
		// source events of other modules, and the order the modules are installed in does not
		// guarantee they are all there in this hit, so the transition agent repeats this call.
		$installBindingResult = TableGroupBindingInstaller::install();
		if (!$installBindingResult->isSuccess())
		{
			$result->addErrors($installBindingResult->getErrors());
		}

		foreach ($roleMap->getRoles() as $role)
		{
			$installRoleResult = $role->install();
			if (!$installRoleResult->isSuccess())
			{
				foreach ($installRoleResult->getErrors() as $error)
				{
					$result->addError(new Error($error->getMessage(), $error->getCode(), ['ROLE_NAME' => $role->getCode()]));
				}
			}
		}

		return $result;
	}

	public static function reinstall($isNewPortal = false, ?RoleMap $roleMap = null): void
	{
		self::clearRelations();
		self::install($isNewPortal, $roleMap);
	}

	public static function getDefaultGroupName(string $groupCode, ?string $language = null): string
	{
		return Loc::getMessage('BICONNECTOR_SYSTEM_GROUP_NAME_' . strtoupper($groupCode), null, $language) ?? '';
	}

	protected static function getRoleMap($isNewPortal = true): RoleMap
	{
		return new RoleMap($isNewPortal);
	}

	public static function clearRelations(): void
	{
		RoleRelationTable::deleteList(['>ID' => 0]);
		RoleTable::deleteList(['>ID' => 0]);
		PermissionTable::deleteList(['>ID' => 0]);
		SupersetDashboardGroupTable::deleteByFilter(['>ID' => 0]);
		SupersetDashboardGroupScopeTable::deleteByFilter(['>ID' => 0]);
		SupersetDashboardGroupBindingTable::deleteByFilter(['>ID' => 0]);
		TableGroupTable::deleteByFilter(['>ID' => 0]);
		TableGroupBindingTable::deleteByFilter(['>ID' => 0]);
		// The groups are dropped past TableGroupService, so the snapshot of the membership
		// would keep serving the deleted composition until the end of the hit.
		TableGroupMembershipService::clearCache();
	}

	private static function installDefaultGroups(): Result
	{
		$result = new Result();

		$systemGroups = SupersetDashboardGroupTable::getList([
			'filter' => ['=TYPE' => SupersetDashboardGroupTable::GROUP_TYPE_SYSTEM],
			'select' => ['CODE'],
		])
			->fetchAll()
		;

		$existedSystemCodes = array_column($systemGroups, 'CODE');

		$newSystemCodeList = array_diff(ScopeService::getSystemGroupCode(), $existedSystemCodes);

		foreach ($newSystemCodeList as $groupCode)
		{
			$group = SupersetDashboardGroupTable::createObject();
			$group->setCode($groupCode);
			$group->setType(SupersetDashboardGroupTable::GROUP_TYPE_SYSTEM);
			$group->setName(self::getDefaultGroupName($groupCode));

			$saveGroupResult = $group->save();
			if (!$saveGroupResult->isSuccess())
			{
				$result->addErrors($saveGroupResult->getErrors());

				return $result;
			}

			$scope = SupersetDashboardGroupScopeTable::createObject();
			$scope->setGroup($group);
			$scope->setScopeCode($groupCode);
			$saveScopeResult = $scope->save();
			if (!$saveScopeResult->isSuccess())
			{
				$result->addErrors($saveScopeResult->getErrors());

				return $result;
			}
		}

		return $result;
	}
}
