<?php

namespace Bitrix\BIConnector\Access\Model;

use Bitrix\BIConnector\Access\Permission\PermissionTable;
use Bitrix\BIConnector\Access\Role\RoleRelationTable;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Role\RoleUtil;
use Bitrix\Main;

final class UserAccessItem extends Main\Access\User\UserModel
{
	private ?array $permissions = null;
	private ?bool $isAdminResolved = null;

	public function isAdmin(): bool
	{
		if ($this->isAdminResolved !== null)
		{
			return $this->isAdminResolved;
		}

		if ($this->userId <= 0)
		{
			$this->isAdminResolved = false;

			return $this->isAdminResolved;
		}

		$this->isAdminResolved =
			parent::isAdmin()
			|| (
				Main\Loader::includeModule('bitrix24')
				&& (
					\CBitrix24::IsPortalAdmin($this->userId)
					|| \Bitrix\Bitrix24\Integrator::isIntegrator($this->userId)
				)
			)
		;

		return $this->isAdminResolved;
	}

	public function getRoles(): array
	{
		if ($this->roles === null)
		{
			$this->roles = [];
			if ($this->userId === 0 || empty($this->getAccessCodes()))
			{
				return $this->roles;
			}

			$relationRows = RoleRelationTable::query()
				->addSelect('ROLE_ID')
				->whereIn('RELATION', $this->getAccessCodes())
				->exec()
				->fetchAll()
			;

			$this->roles = array_unique(
				array_column($relationRows, 'ROLE_ID')
			);
		}

		return $this->roles;
	}

	public function getPermission(string $permissionId): ?int
	{
		$permissions = $this->getPermissions();

		$permissions[$permissionId] = $permissions[$permissionId] ?? null;

		if (is_array($permissions[$permissionId]))
		{
			$permissions[$permissionId] =
				isset($permissions[$permissionId][0])
					? (int)$permissions[$permissionId][0]
					: null
			;
		}

		return $permissions[$permissionId];
	}

	/**
	 * Returns multiple permission if exists.
	 *
	 * @param string $permissionId String identificatior of permission.
	 *
	 * @return array|null
	 */
	public function getPermissionMulti(string $permissionId): ?array
	{
		if ($this->isAdmin())
		{
			return [PermissionDictionary::VALUE_VARIATION_ALL];
		}

		$permissions = $this->getPermissions();
		$permissions[$permissionId] = $permissions[$permissionId] ?? null;

		return is_array($permissions[$permissionId]) ? $permissions[$permissionId] : null;
	}

	/**
	 * Returns permission list in format ['permission_id' => 1 or 0].
	 *
	 * @return array
	 */
	private function getPermissions(): array
	{
		if ($this->permissions !== null)
		{
			return $this->permissions;
		}

		$this->permissions = [];
		$roles = $this->getRoles();

		if (empty($roles))
		{
			return $this->permissions;
		}

		$query = PermissionTable::query();

		$permissions = $query
			->addSelect('PERMISSION_ID')
			->addSelect('VALUE')
			->whereIn('ROLE_ID', $roles)
			->exec()
		;
		while ($permission = $permissions->fetch())
		{
			$permissionId = $permission['PERMISSION_ID'];
			$value = (int)$permission['VALUE'];
			$permissionDescription = PermissionDictionary::getPermission($permissionId);

			if (
				$permissionDescription['type'] === PermissionDictionary::TYPE_MULTIVARIABLES
				|| $permissionDescription['type'] === PermissionDictionary::TYPE_DEPENDENT_VARIABLES
			)
			{
				$this->permissions[$permissionId] = $this->permissions[$permissionId] ?? [];
				if (in_array(PermissionDictionary::VALUE_VARIATION_ALL, $this->permissions[$permissionId], true))
				{
					continue;
				}

				if ($value === PermissionDictionary::VALUE_VARIATION_ALL)
				{
					$this->permissions[$permissionId] = [$value];
				}
				elseif (!in_array($value, $this->permissions[$permissionId], true))
				{
					$this->permissions[$permissionId][] = $value;
				}
			}
			else
			{
				$this->permissions[$permissionId] = $this->permissions[$permissionId] ?? 0;
				if ($value > $this->permissions[$permissionId])
				{
					$this->permissions[$permissionId] = $value;
				}
			}
		}

		return $this->permissions;
	}

	public function canDeleteRestApp(): bool
	{
		$currentUser = Main\Engine\CurrentUser::get();

		return $this->isAdmin() || $currentUser->canDoOperation('bitrix24_config');
	}

	/**
	 * Grants the rows of a new group of dashboards to the roles that manage the rights of that axis.
	 *
	 * The receiver is a role of the creator holding the right of the dashboard section: the screen is
	 * one and the roles are common to it, so the role that may edit the rows of this axis is the role
	 * that gets them. A group granted to nobody would show the creator "granted to nobody" over an
	 * access he has just opened.
	 *
	 * @return bool Whether anything was granted.
	 *
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 */
	public function fillDefaultGroupAccess(int $groupId): bool
	{
		$roles = $this->getRoles();

		if (empty($roles))
		{
			return false;
		}

		$accessibleRoles = $this->getRolesManagingDashboardRights($roles);
		if (empty($accessibleRoles))
		{
			return false;
		}

		$permissionGroupId = PermissionDictionary::getDashboardGroupPermissionId($groupId);
		$defaultPermissionValues = PermissionDictionary::getDefaultPermissionGroupValues();

		$dashboardPermissions = [];
		foreach ($accessibleRoles as $roleId)
		{
			foreach ($defaultPermissionValues as $defaultValue)
			{
				$dashboardPermissions[] = [
					'ROLE_ID' => $roleId,
					'PERMISSION_ID' => $permissionGroupId,
					'VALUE' => $defaultValue,
				];
			}
		}

		RoleUtil::insertPermissions($dashboardPermissions);

		return true;
	}

	/**
	 * Roles of the person that may edit the rights of the dashboard section.
	 *
	 * @param int[] $roles
	 *
	 * @return int[]
	 */
	private function getRolesManagingDashboardRights(array $roles): array
	{
		$rows = PermissionTable::query()
			->addSelect('ROLE_ID')
			->whereIn('ROLE_ID', $roles)
			->where('PERMISSION_ID', PermissionDictionary::BIC_SECTION_EDIT_DASHBOARD_RIGHTS)
			->where('VALUE', 1)
			->exec()
			->fetchAll()
		;

		return array_unique(array_map('intval', array_column($rows, 'ROLE_ID')));
	}

	/**
	 * Fills default table group access permissions for roles that have edit rights.
	 *
	 * The operations come from the table axis: the codes of the dashboard axis would mean something
	 * else under a table group permission id.
	 *
	 * @param int $groupId
	 *
	 * @return bool Whether anything was granted.
	 *
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 *
	 * @deprecated No caller left: a new table group is opened to nobody. The role that holds the
	 *             rights of the table axis carries no right 4, so the selection below would find no
	 *             role anyway. Kept as the counterpart of fillDefaultGroupAccess() until the axis
	 *             decides how a new table group is opened.
	 */
	public function fillDefaultTableGroupAccess(int $groupId): bool
	{
		$roles = $this->getRoles();

		if (empty($roles))
		{
			return false;
		}

		$roleEditableRights = PermissionTable::query()
			->addSelect('ROLE_ID')
			->whereIn('ROLE_ID', $roles)
			->where('PERMISSION_ID', PermissionDictionary::BIC_SETTINGS_EDIT_TABLE_RIGHTS)
			->where('VALUE', 1)
			->exec()
		;

		$accessibleRoles = array_column($roleEditableRights->fetchAll(), 'ROLE_ID');
		if (empty($accessibleRoles))
		{
			return false;
		}

		$permissionGroupId = PermissionDictionary::getTableGroupPermissionId($groupId);
		$permissionCodes = PermissionDictionary::getTablePermissionCodes();

		$tablePermissions = [];
		foreach ($accessibleRoles as $roleId)
		{
			foreach ($permissionCodes as $permissionCode)
			{
				$tablePermissions[] = [
					'ROLE_ID' => $roleId,
					'PERMISSION_ID' => $permissionGroupId,
					'VALUE' => $permissionCode,
				];
			}
		}

		RoleUtil::insertPermissions($tablePermissions);

		return true;
	}
}
