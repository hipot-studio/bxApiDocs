<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class MyCompaniesNavigationItem implements SettingsHubUrlNavigationItem
{
	public function id(): string
	{
		return 'my-companies';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin();
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$permissions->myCompany()->canRead())
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_MY_COMPANIES_ACCESS'),
					'CRM_SETTINGS_HUB_NAVIGATION_MY_COMPANIES_ACCESS',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/mycompany/');
	}
}
