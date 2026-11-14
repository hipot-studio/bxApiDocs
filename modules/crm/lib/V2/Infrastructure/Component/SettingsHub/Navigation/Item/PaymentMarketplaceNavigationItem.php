<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\V2\Internal\Integration\Market\MarketplaceGateway;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class PaymentMarketplaceNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $isMarketOrRestAvailable,
		private MarketplaceGateway $marketplace,
	)
	{
	}

	public function id(): string
	{
		return 'payment-marketplace';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin();
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isMarketOrRestAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_PAYMENT_MARKETPLACE_MODULES'),
					'CRM_SETTINGS_HUB_NAVIGATION_PAYMENT_MARKETPLACE_MODULES',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->marketplace->getCategoryUrl('payment');
	}
}
