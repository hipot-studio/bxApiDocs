<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Install\AgentInstaller;

use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Repository\PermissionRepository;
use Bitrix\Mail\Access\Role\RoleDictionary;
use Bitrix\Main\Access\Permission\PermissionDictionary as MainPermissionDictionary;
use Bitrix\Main\SystemException;

class SenderAvatarPermissionInstaller extends AbstractInstaller
{
	private PermissionRepository $permissionRepository;

	public function __construct(?PermissionRepository $permissionRepository = null)
	{
		parent::__construct();
		$this->permissionRepository = $permissionRepository ?? new PermissionRepository();
	}

	protected function run(): void
	{
		$roleId = $this->roleRepository->getRoleIdByName(RoleDictionary::ROLE_ADMIN);
		if ($roleId === null)
		{
			return;
		}

		$currentPermissions = $this->permissionRepository->getPermissionsForRole($roleId);
		if (array_key_exists(PermissionDictionary::MAIL_SENDER_AVATARS_MANAGE, $currentPermissions))
		{
			return;
		}

		$result = $this->permissionRepository->addWithResult(
			$roleId,
			PermissionDictionary::MAIL_SENDER_AVATARS_MANAGE,
			MainPermissionDictionary::VALUE_YES,
		);
		if (!$result->isSuccess())
		{
			throw new SystemException(implode('; ', $result->getErrorMessages()));
		}
	}
}
