<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\V2\Internal\Integration\Rest\MarketplaceGateway;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

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

	public function checkAvailability(UserPermissions $permissions): Result
	{
		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->marketplace->getConfigurationPlacementUrl();
	}
}
