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
		return $permissions->isCrmAdmin() && $this->isCloud;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isRestAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_CRMMIGRATION_REST_UNAVAILABLE'),
					'CRMMIGRATION_REST_UNAVAILABLE',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->marketplace->getMigrationUrl($this->isMarketAvailable);
	}
}
