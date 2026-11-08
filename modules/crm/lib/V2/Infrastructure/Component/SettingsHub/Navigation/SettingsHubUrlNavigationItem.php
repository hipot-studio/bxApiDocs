<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Main\Web\Uri;

interface SettingsHubUrlNavigationItem extends SettingsHubNavigationItem
{
	public function url(): ?Uri;
}
