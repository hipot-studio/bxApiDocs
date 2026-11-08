<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Currency\CurrencyAdminAccess;
use Bitrix\Main\Web\Uri;

final class CurrenciesNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(private CurrencyAdminAccess $access)
	{
	}

	public function id(): string
	{
		return 'currencies';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->access->canManage($permissions->getUserId());
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/currency/');
	}
}
