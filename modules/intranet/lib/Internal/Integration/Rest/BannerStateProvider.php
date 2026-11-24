<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\Marketplace\Client;
use Bitrix\Rest\Public\Provider\ApplicationProvider;

class BannerStateProvider
{
	public function isTrialActive(): bool
	{
		return Loader::includeModule('rest') && Client::isSubscriptionDemo();
	}

	public function hasInstalledApps(): bool
	{
		return Loader::includeModule('rest')
			&& (new ApplicationProvider())->getInstalledCount() > 0;
	}
}
