<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\Service\RolePermissionService;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

class SignConfigPermissionsComponent extends SignBaseComponent
{

	public function exec(): void
	{
		$GLOBALS['APPLICATION']->SetTitle(Loc::getMessage('SIGN_CONFIG_PERMISSIONS'));
		$this->arResult['ACCESS_RIGHTS'] = (new RolePermissionService())->getAccessRights();
		$this->arResult['USER_GROUPS'] = (new RolePermissionService())->getUserGroups();
		
		if ($this->request->getQuery('resetAccessRights') === 'y'
			&& $this->accessController->check(ActionDictionary::ACTION_ACCESS_RIGHTS))
		{
			// Both halves of the reset are done by one call under one lock: taken between them, the lock of
			// the permission table could be missing for the sign half alone, and the reset would leave the
			// portal with the CRM roles created anew and the rows of the roles it had just dropped. A reset
			// that could not take the lock changes nothing and raises - the exception is left to surface,
			// because this query is a service entry point with no link to it anywhere, and the one who typed
			// it needs to know the reset did not run rather than read the unchanged rights as reset.
			\Bitrix\Sign\Access\Install\AccessInstaller::reinstallWithCrmRoles();
		}
	}
	
	public function getAction(): array
	{
		return [
			\Bitrix\Sign\Access\AccessController::RULE_AND => [
				ActionDictionary::ACTION_ACCESS_RIGHTS,
			]
		];
	}
}