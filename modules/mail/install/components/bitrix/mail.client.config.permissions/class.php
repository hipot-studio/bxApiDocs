<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die;
}

if (!\Bitrix\Main\Loader::includeModule('mail'))
{
	return;
}

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Localization\Loc;

class MailConfigPermissionsComponent extends \CBitrixComponent implements Controllerable
{
	public function configureActions(): array
	{
		return [];
	}

	public function executeComponent(): void
	{
		$canManage = MailAccess::hasCurrentUserAccessToPermission();

		if (!$canManage)
		{
			$this->includeComponentTemplate('access_denied');

			return;
		}

		$this->prepareResult();
		$this->includeComponentTemplate();
	}

	private function prepareResult(): void
	{
		$title = Loc::getMessage('MAIL_CONFIG_PERMISSIONS_TITLE');
		$this->arResult['CONFIG_PERMISSION_TITLE'] = $title;

		$rolePermission = new \Bitrix\Mail\Access\Service\RolePermissionService();

		$this->arResult['ACCESS_RIGHTS'] = $rolePermission->getAccessRights();
		$this->arResult['USER_GROUPS'] = $rolePermission->getUserGroups();

		$this->arResult['ANALYTICS_CONTEXT'] = [
			'tool' => 'mail',
			'category' => 'permissions_settings',
		];
	}
}
