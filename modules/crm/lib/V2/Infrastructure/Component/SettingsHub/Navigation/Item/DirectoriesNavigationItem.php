<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

final class DirectoriesNavigationItem implements SettingsHubUrlNavigationItem
{
	public function id(): string
	{
		return 'directories';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin();
	}

	public function url(): ?Uri
	{
		$localPath = getLocalPath('components/bitrix/crm.config.status/slider.php');
		if (!$localPath)
		{
			return null;
		}

		return new Uri($localPath);
	}
}
