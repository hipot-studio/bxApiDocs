<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Rest\Service\ServiceContainer;

class ApplicationProvider
{
	public function getInstalledCount(): int
	{
		return ServiceContainer::getInstance()->getAppService()->getInstalledAppsCount();
	}
}