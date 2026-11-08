<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;
use Bitrix\Crm\V2\Internal\Integration\Rest\MarketplaceGateway;

final class AppConfigurationNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $isRestAvailable,
		private bool $isMarketplaceUrlAvailable,
		private MarketplaceGateway $marketplace,
	)
	{
	}

	public function id(): string
	{
		return 'app-configuration';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin()
			&& $this->isRestAvailable
			&& $this->isMarketplaceUrlAvailable;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return true;
	}

	public function url(): ?Uri
	{
		return $this->marketplace->getConfigurationPlacementUrl();
	}
}
