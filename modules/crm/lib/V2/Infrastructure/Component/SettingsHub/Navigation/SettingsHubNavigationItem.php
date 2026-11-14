<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Result;

interface SettingsHubNavigationItem
{
	public function id(): string;

	public function isVisible(UserPermissions $permissions): bool;

	public function checkAvailability(UserPermissions $permissions): Result;
}
