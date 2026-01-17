<?php

namespace Bitrix\Mail\Access\Install;

use Bitrix\Mail\Access\Repository\RoleRepository;
use Bitrix\Mail\Access\Role\RoleDictionary;
use Bitrix\Mail\Access\Role\RoleUtil;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;

class AccessInstaller
{
	public static function install(): string
	{
		self::fillSystemPermissions();

		return '';
	}

	private static function fillSystemPermissions(): void
	{
		$connection = Application::getConnection();
		if (!$connection->lock('mailbox_config_access_install'))
		{
			return;
		}

		try
		{
			if (self::areDefaultRoleDefined())
			{
				$connection->unlock('mailbox_config_access_install');

				return;
			}

			$defaultRoles = RoleUtil::getDefaultMap();
			foreach ($defaultRoles as $roleName => $permissions)
			{
				$roleId = RoleUtil::createRole($roleName);
				$role = new RoleUtil($roleId);

				$permissionValues = [];
				foreach ($permissions as $permission)
				{
					$permissionValues[$permission['id']] = $permission['value'];
				}
				$role->updatePermissions($permissionValues);

				$accessCodes = [];
				switch ($roleName)
				{
					case RoleDictionary::ROLE_DIRECTOR:
						$accessCodes = [AccessCode::ACCESS_DIRECTOR . '0' => 'access_director'];

						break;
					case RoleDictionary::ROLE_EMPLOYEE:
						$accessCodes = [AccessCode::ACCESS_EMPLOYEE . '0' => 'groups'];

						break;
				}


				if (!empty($accessCodes))
				{
					$role->updateRoleRelations($accessCodes);
				}
			}
		}
		catch (\Exception $e)
		{
		}
		finally
		{
			$connection->unlock('mailbox_config_access_install');
		}
	}

	private static function areDefaultRoleDefined(): bool
	{
		$roleRepository = new RoleRepository();
		$roles = $roleRepository->getRoleList();
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
}
