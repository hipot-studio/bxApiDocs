<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Measure\MeasureAccess;
use Bitrix\Main\Web\Uri;

final class MeasuresNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(private MeasureAccess $access)
	{
	}

	public function id(): string
	{
		return 'measures';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->access->canEdit();
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/measure/');
	}
}
