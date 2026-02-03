<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Install\AgentInstaller;

use Bitrix\Mail\Access\Repository\RoleRepository;
use Bitrix\Mail\Access\Role\RoleUtil;

class PermissionReInstaller extends AbstractInstaller
{
	protected function run(): void
	{
		$roleRepository = new RoleRepository();
		if (!$roleRepository->areRolesDefined())
		{
			return;
		}

		$roles = $roleRepository->getRoleList();

		foreach ($roles as $role)
		{
			$roleUtil = new RoleUtil((int)$role['ID']);

			$roleUtil->deleteRole();
		}
	}
}
