<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

final class MyCompaniesNavigationItem implements SettingsHubUrlNavigationItem
{
	public function id(): string
	{
		return 'my-companies';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $permissions->myCompany()->canRead();
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/mycompany/');
	}
}
