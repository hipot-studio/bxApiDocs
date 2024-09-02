<?php

namespace Bitrix\HumanResources\Access\Install;

use Bitrix\Bxtest\Codeception\Module\Bitrix;
use Bitrix\HumanResources\Access\Role;
use Bitrix\HumanResources\Item\Collection\Access\PermissionCollection;
use Bitrix\HumanResources\Repository\Access\RoleRepository;
use Bitrix\HumanResources\Repository\Access\PermissionRepository;
use Bitrix\HumanResources\Item;

class AccessInstaller
{
	public static function installAgent(): string
	{
		self::fillDefaultSystemPermissions();
		return '';
	}

	private static function fillDefaultSystemPermissions(): void
	{
		$roleRepository = new RoleRepository();
		if ($roleRepository->areRolesDefined())
		{
			return;
		}

		$defaultMap = Role\RoleUtil::getDefaultMap();

		$permissionCollection = new PermissionCollection();
		$permissionRepository = new PermissionRepository();

		foreach ($defaultMap as $roleName => $rolePermissions)
		{
			$role = $roleRepository->create(Role\RoleDictionary::getTitle($roleName));

			if (!$role->isSuccess())
			{
				continue;
			}

			$roleId = $role->getId();
			foreach ($rolePermissions as $permission)
			{
				$permissionCollection->add(
					new Item\Access\Permission(
						roleId: (int)$roleId,
						permissionId: $permission['id'],
						value: (int)$permission['value'],
					)
				);
			}
		}

		if (!$permissionCollection->empty())
		{
			$permissionRepository->createByCollection($permissionCollection);
		}
	}
}