<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\Marketplace\Client;

final class MarketSubscriptionProvider
{
	public function isAvailable(): bool
	{
		return Loader::includeModule('rest') && Client::isSubscriptionAvailable();
	}
}
