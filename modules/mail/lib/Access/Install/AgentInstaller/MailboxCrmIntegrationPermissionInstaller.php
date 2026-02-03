<?php

namespace Bitrix\Mail\Access\Install\AgentInstaller;

use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Repository\RoleRepository;
use Bitrix\Mail\Access\Role\RoleUtil;
use Bitrix\Main\Access\Exception\RoleNotFoundException;
use Bitrix\Main\Access\Exception\RoleSaveException;
use Bitrix\Main\Db\SqlQueryException;

class MailboxCrmIntegrationPermissionInstaller extends AbstractInstaller
{
	protected function run(): void
	{
		try
		{
			$this->setMailboxCrmIntegrationPermissions();
		}
		catch (\Exception $e)
		{
		}
	}

	/**
	 * @throws SqlQueryException
	 * @throws RoleNotFoundException
	 * @throws RoleSaveException
	 */
	private function setMailboxCrmIntegrationPermissions(): void
	{
		$rolesToPermissions = RoleUtil::getDefaultMap();
		$roleRepository = new RoleRepository();
		foreach ($rolesToPermissions as $roleName => $rolePermissions)
		{
			$roleId = $roleRepository->getRoleIdByName($roleName);
			if (!$roleId)
			{
				continue;
			}

			$role = new RoleUtil($roleId);

			$permissionIds = array_column($rolePermissions, 'id');
			$key = array_search(
				PermissionDictionary::MAIL_MAILBOX_CRM_INTEGRATION_EDIT,
				$permissionIds,
				true,
			);

			$mailboxCrmIntegrationPermission = ($key !== false) ? $rolePermissions[$key] : null;
			if (is_null($mailboxCrmIntegrationPermission))
			{
				continue;
			}

			$roleRepository->addPermissionsFromMap($role, [$mailboxCrmIntegrationPermission]);
		}
	}
}
