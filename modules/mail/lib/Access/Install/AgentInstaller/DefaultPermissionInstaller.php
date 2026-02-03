<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Install\AgentInstaller;

use Bitrix\Mail\Access\Repository\RoleRepository;
use Bitrix\Mail\Access\Role\RoleUtil;
use Bitrix\Main\Access\Exception\RoleRelationSaveException;

class DefaultPermissionInstaller extends AbstractInstaller
{
	/**
	 * @throws RoleRelationSaveException
	 */
	protected function run(): void
	{
		$roleRepository = new RoleRepository();
		if ($roleRepository->areRolesDefined())
		{
			return;
		}

		$this->fillDefaultSystemPermissions(RoleUtil::getDefaultMap());
	}
}
