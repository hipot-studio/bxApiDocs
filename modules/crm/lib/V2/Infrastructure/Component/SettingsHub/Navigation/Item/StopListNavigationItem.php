<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Exclusion\Access;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

final class StopListNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(private Access $access)
	{
	}

	public function id(): string
	{
		return 'stop-list';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $this->access->canRead();
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		return new Result();
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/exclusion/');
	}
}
