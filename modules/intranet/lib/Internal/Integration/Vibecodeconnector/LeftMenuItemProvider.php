<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Vibecodeconnector;

use Bitrix\Intranet\UI\LeftMenu\Menu;
use Bitrix\Intranet\UI\LeftMenu\MenuItem\ItemVibecode;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Public\Provider\VibecodeUrlProvider;
use Bitrix\Vibecodeconnector\Public\Provider\VibecodeBrandProvider;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;

class LeftMenuItemProvider
{
	public function getForUser(int $userId, string $siteId): ?ItemVibecode
	{
		if ($siteId !== $this->getMainSiteId() || !Loader::includeModule('vibecodeconnector'))
		{
			return null;
		}

		if (!(new AvailabilityService())->isAvailableForUser($userId))
		{
			return null;
		}

		return new ItemVibecode(
			(new VibecodeBrandProvider())->getDisplayName(),
			(new VibecodeUrlProvider())->getDashboardUrl($userId),
		);
	}

	protected function getMainSiteId(): string
	{
		return \CSite::GetDefSite() ?: Menu::getDefaultSiteId();
	}
}
