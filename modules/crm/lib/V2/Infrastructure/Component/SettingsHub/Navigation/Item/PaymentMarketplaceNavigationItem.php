<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Internal\Integration\Market\MarketplaceGateway;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

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
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isMarketOrRestAvailable;
	}

	public function url(): ?Uri
	{
		return $this->marketplace->getCategoryUrl('payment');
	}
}
