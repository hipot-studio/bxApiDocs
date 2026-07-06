<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App;

use Bitrix\Rest\Internal\Entity\Access\PermissionType;

enum AppAction: string
{
	case UseApp = 'use_app';
	case ManageAppAccess = 'manage_app_access';
	case InstallLocalApp = 'install_local_app';
	case UninstallLocalApp = 'uninstall_local_app';
	case InstallPersonalApp = 'install_personal_app';
	case UninstallPersonalApp = 'uninstall_personal_app';
	case ViewInstalledList = 'view_installed_list';

	public function getPermissionType(): ?PermissionType
	{
		return match ($this)
		{
			self::InstallLocalApp, self::UninstallLocalApp => PermissionType::Create,
			self::InstallPersonalApp, self::UninstallPersonalApp => PermissionType::CreateOwn,
			self::ManageAppAccess => PermissionType::Manage,
			default => null,
		};
	}
}
