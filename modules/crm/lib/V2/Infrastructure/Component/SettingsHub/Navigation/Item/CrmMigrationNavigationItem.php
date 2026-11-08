<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Internal\Integration\Market\MarketplaceGateway;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

final class CrmMigrationNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $isCloud,
		private bool $isRestAvailable,
		private bool $isMarketAvailable,
		private MarketplaceGateway $marketplace,
	)
	{
	}

	public function id(): string
	{
		return 'crm-migration';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isCloud && $this->isRestAvailable;
	}

	public function url(): ?Uri
	{
		return $this->marketplace->getMigrationUrl($this->isMarketAvailable);
	}
}
