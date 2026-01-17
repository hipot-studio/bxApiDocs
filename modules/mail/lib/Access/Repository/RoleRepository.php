<?php

namespace Bitrix\Mail\Access\Repository;

use Bitrix\Mail\Access\Role\RoleUtil;
use Bitrix\Mail\Internals\Access\AccessRoleTable;
use Bitrix\Main\Access\Exception\RoleNotFoundException;
use Bitrix\Main\Access\Exception\RoleSaveException;

class RoleRepository
{
	/**
	 * @return array<array{ID: int, NAME: string}>
	 */
	public function getRoleList(): array
	{
		return RoleUtil::getRoles();
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
}
