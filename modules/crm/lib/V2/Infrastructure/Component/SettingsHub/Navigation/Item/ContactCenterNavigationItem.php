<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

final class ContactCenterNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(private Router $router)
	{
	}

	public function id(): string
	{
		return 'contact-center';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->router->getContactCenterUrl();
	}
}
