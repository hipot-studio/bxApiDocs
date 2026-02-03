<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Repository;

use Bitrix\Mail\Access\Role\RoleDictionary;
use Bitrix\Mail\Access\Role\RoleUtil;
use Bitrix\Mail\Internals\Access\AccessRoleTable;
use Bitrix\Main\Access\Exception\RoleNotFoundException;
use Bitrix\Main\Access\Exception\RoleSaveException;
use Bitrix\Main\Db\SqlQueryException;

class RoleRepository
{
	/**
	 * @return array<array{ID: int, NAME: string}>
	 */
	public function getRoleList(): array
	{
		$roles = RoleUtil::getRoles();

		foreach ($roles as &$role)
		{
			$role['ID'] = (int)$role['ID'];
		}
		unset($role);

		return $roles;
	}

	/**
	 * @throws RoleSaveException
	 */
	public function create(string $roleName): int
	{
		return RoleUtil::createRole($roleName);
	}

	/**
	 * @throws RoleNotFoundException
	 */
	public function updateTitle(int $roleId, string $title): void
	{
		(new RoleUtil($roleId))->updateTitle($title);
	}

	public function deleteByIds(array $roleIds): void
	{
		if (empty($roleIds))
		{
			return;
		}

		AccessRoleTable::deleteList(['@ID' => $roleIds]);
	}

	public function getRoleIdByName(string $roleName): ?int
	{
		$roles = $this->getRoleList();

		$roleNames = array_column($roles, 'NAME');
		$key = array_search($roleName, $roleNames, true);

		return ($key !== false) ? $roles[$key]['ID'] : null;
	}

	public function areRolesDefined(): bool
	{
		$roles = $this->getRoleList();
		if (empty($roles))
		{
			return false;
		}

		foreach ($roles as $role)
		{
			if (in_array((string)($role['NAME'] ?? ''), [
				RoleDictionary::ROLE_ADMIN,
				RoleDictionary::ROLE_DIRECTOR,
				RoleDictionary::ROLE_EMPLOYEE,
			], true))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $permissions array<array{id: string, value: string}>
	 *
	 * @throws SqlQueryException
	 * @throws RoleSaveException
	 * @throws RoleNotFoundException
	 */
	public function addPermissionsFromMap(RoleUtil $role, array $permissions): void
	{
		$mappedNewPermissions = $this->mapPermissionIdToValue($permissions);
		$currentRolePermissions = $role->getPermissions();
		$newRolePermissions = $currentRolePermissions + $mappedNewPermissions;
		$role->updatePermissions($newRolePermissions);
	}

	/**
	 * @param $permissions array<array{id: string, value: string}>
	 *
	 * @throws SqlQueryException
	 * @throws RoleSaveException
	 * @throws RoleNotFoundException
	 */
	public function updatePermissionsFromMap(RoleUtil $role, array $permissions): void
	{
		$permissionValues = $this->mapPermissionIdToValue($permissions);
		$role->updatePermissions($permissionValues);
	}

	/**
	 * @param $permissions array<array{id: string, value: string}>
	 */
	public function mapPermissionIdToValue(array $permissions): array
	{
		$permissionValues = [];
		foreach ($permissions as $permission)
		{
			$permissionValues[$permission['id']] = (string)$permission['value'];
		}

		return $permissionValues;
	}
}
