<?php

namespace Bitrix\Sign\Agent\Permission;

use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Access;

class ReinstallAccessPermissionsAgent
{
	public static function run(): string
	{
		$documentRepository = Container::instance()->getDocumentRepository();
		if (!$documentRepository->existAnyDocument())
		{
			$anyPermission = Access\Permission\PermissionTable::query()
				->setSelect(['ID'])
				->setLimit(1)
				->fetchObject()
			;

			if ($anyPermission === null)
			{
				Access\Install\AccessInstaller::install();

				return '';
			}
		}

		Access\Install\AccessInstaller::installMissingSafeFolderPermissions();

		return '';
	}
}
