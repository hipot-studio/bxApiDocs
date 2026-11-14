<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\V2\Internal\Access\Currency\CurrencyAdminAccess;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

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
		return $permissions->isCrmAdmin();
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->access->canManage($permissions->getUserId()))
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_CURRENCIES_NO_ACCESS'),
					'CURRENCIES_NO_ACCESS',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/currency/');
	}
}
