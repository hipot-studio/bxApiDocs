<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Crm\Service\UserPermissions;

interface SettingsHubNavigationItem
{
	public function id(): string;

	public function isVisible(UserPermissions $permissions): bool;

	public function canRead(UserPermissions $permissions): bool;
}
